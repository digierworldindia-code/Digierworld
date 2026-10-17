/*
 * Camera QR scanning for the warehouse and field-agent panels.
 *
 * One scanner, three jobs: counting units into a production batch, picking
 * units onto a dispatch, and checking a dealer's shelf against the records.
 * The page only says where to post; everything the operator sees comes back
 * from the server, because the server is the only thing that knows whether a
 * label has already been counted.
 *
 * Design notes, in the order they matter on a warehouse floor:
 *
 *  - The camera stays open. Scanning one hundred mattresses means one hundred
 *    labels in front of one lens, not one hundred taps on "scan again".
 *
 *  - A label held in frame decodes on every frame. Submitting each of those
 *    would hammer the server and flood the log, so the same code is ignored
 *    for a short cooldown. It is a cooldown, not a block: deliberately
 *    rescanning the same unit a moment later still reaches the server and
 *    still gets the honest "already scanned" answer, because another operator
 *    may have scanned it in the meantime.
 *
 *  - One request at a time. Two frames decoding the same new label a
 *    millisecond apart must not both be counted; the second waits, sees the
 *    cooldown, and is dropped.
 *
 *  - The count is whatever the server says it is. It is never incremented
 *    locally, so a dropped reply shows as "not recorded" rather than as a
 *    number that quietly disagrees with the database.
 *
 *  - Sound and vibration, because the operator is looking at the mattress,
 *    not at the phone. Three different signals: accepted, already scanned,
 *    not accepted.
 *
 *  - Typing a serial is the fallback, never the workflow. It is there for a
 *    torn label, a denied camera and a device without one.
 *
 * Decoding uses the browser's own BarcodeDetector where it exists (Chrome on
 * Android, which is what these devices are). Only where it does not — iOS
 * Safari, mainly — is the vendored jsQR decoder fetched, and then only once.
 *
 * No inline script anywhere: the Content-Security-Policy allows scripts from
 * this origin only. Everything is driven by data- attributes on the markup.
 */
(function () {
  'use strict';

  var SCAN_INTERVAL_MS = 120;   // ~8 decode attempts a second: enough to feel instant, light on the battery.
  var SAME_CODE_COOLDOWN_MS = 1500;
  var LOG_LIMIT = 50;

  /* ---------------------------------------------------------------- sound */

  /*
   * Generated rather than loaded: no audio file to ship, to cache or to let
   * through the policy, and the tones can differ by outcome. The context is
   * created on the first beep, which always follows the tap that started the
   * camera, so autoplay rules are satisfied.
   */
  var audio = null;

  function beep(pattern) {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      if (!audio) audio = new Ctx();
      if (audio.state === 'suspended') audio.resume();

      var at = audio.currentTime;
      pattern.forEach(function (note) {
        var osc = audio.createOscillator();
        var gain = audio.createGain();
        osc.type = 'sine';
        osc.frequency.value = note[0];
        gain.gain.setValueAtTime(0.0001, at);
        gain.gain.exponentialRampToValueAtTime(0.25, at + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, at + note[1]);
        osc.connect(gain);
        gain.connect(audio.destination);
        osc.start(at);
        osc.stop(at + note[1]);
        at += note[1] + 0.04;
      });
    } catch (e) {
      // A device that will not make a noise is not a reason to stop scanning.
    }
  }

  function buzz(pattern) {
    try {
      if (navigator.vibrate) navigator.vibrate(pattern);
    } catch (e) { /* same */ }
  }

  var FEEDBACK = {
    ACCEPTED:  { tones: [[880, 0.09]],                       buzz: 60,          tone: 'success' },
    DUPLICATE: { tones: [[520, 0.07], [520, 0.07]],          buzz: [40, 60, 40], tone: 'warning' },
    REJECTED:  { tones: [[220, 0.22]],                       buzz: 280,         tone: 'danger' },
    ERROR:     { tones: [[220, 0.12], [180, 0.18]],          buzz: [120, 80, 120], tone: 'danger' }
  };

  /* -------------------------------------------------------------- decoding */

  /*
   * Resolves to a function (canvas, pixels) -> Promise<string|null>, where
   * pixels is a getter rather than the data itself: reading the pixels back
   * off a canvas is the expensive part of a frame, and the native detector
   * reads the canvas directly and never needs them.
   *
   * Chosen once per page, not per frame.
   */
  function makeDecoder(jsqrUrl) {
    if (window.BarcodeDetector) {
      return window.BarcodeDetector.getSupportedFormats().then(function (formats) {
        if (formats.indexOf('qr_code') === -1) return loadJsQr(jsqrUrl);

        var detector = new window.BarcodeDetector({ formats: ['qr_code'] });

        return function (canvas) {
          return detector.detect(canvas).then(function (codes) {
            return codes.length ? codes[0].rawValue : null;
          }).catch(function () {
            return null;
          });
        };
      }).catch(function () {
        return loadJsQr(jsqrUrl);
      });
    }

    return loadJsQr(jsqrUrl);
  }

  function loadJsQr(url) {
    return new Promise(function (resolve, reject) {
      if (window.jsQR) return resolve(wrapJsQr());
      if (!url) return reject(new Error('no decoder'));

      var tag = document.createElement('script');
      tag.src = url;
      tag.onload = function () {
        window.jsQR ? resolve(wrapJsQr()) : reject(new Error('decoder did not load'));
      };
      tag.onerror = function () { reject(new Error('decoder did not load')); };
      document.head.appendChild(tag);
    });
  }

  function wrapJsQr() {
    return function (canvas, pixels) {
      var imageData = pixels();

      // dontInvert: labels are printed dark on white. Skipping the inverted
      // pass roughly halves the work per frame.
      var found = window.jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: 'dontInvert' });

      return Promise.resolve(found ? found.data : null);
    };
  }

  /* --------------------------------------------------------------- scanner */

  function Scanner(root) {
    this.root = root;
    this.endpoint = root.getAttribute('data-endpoint');
    this.csrfName = root.getAttribute('data-csrf-name');
    this.csrfToken = root.getAttribute('data-csrf-token');
    this.jsqrUrl = root.getAttribute('data-jsqr');

    this.video = root.querySelector('[data-scanner-video]');
    this.canvas = root.querySelector('[data-scanner-canvas]');
    this.statusBox = root.querySelector('[data-scanner-status]');
    this.counterBox = root.querySelector('[data-scanner-counter]');
    this.log = root.querySelector('[data-scanner-log]');
    this.startButton = root.querySelector('[data-scanner-start]');
    this.stopButton = root.querySelector('[data-scanner-stop]');
    this.torchButton = root.querySelector('[data-scanner-torch]');
    this.manualForm = root.querySelector('[data-scanner-manual]');
    this.frame = root.querySelector('[data-scanner-frame]');

    this.context = this.canvas ? this.canvas.getContext('2d', { willReadFrequently: true }) : null;
    this.stream = null;
    this.decoder = null;
    this.running = false;
    this.busy = false;
    this.lastCode = null;
    this.lastCodeAt = 0;
    this.nextScanAt = 0;
    this.torchOn = false;

    this.bind();
  }

  Scanner.prototype.bind = function () {
    var self = this;

    if (this.startButton) {
      this.startButton.addEventListener('click', function (event) {
        event.preventDefault();
        self.start();
      });
    }

    if (this.stopButton) {
      this.stopButton.addEventListener('click', function (event) {
        event.preventDefault();
        self.stop('Camera off. Press Scan to start again.');
      });
    }

    if (this.torchButton) {
      this.torchButton.addEventListener('click', function (event) {
        event.preventDefault();
        self.toggleTorch();
      });
    }

    if (this.manualForm) {
      this.manualForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var input = self.manualForm.querySelector('input[name="code"]');
        var code = input ? input.value.trim() : '';
        if (code === '') return;
        self.submit(code, 'MANUAL').then(function () {
          input.value = '';
          input.focus();
        });
      });
    }

    // Leaving the page or switching apps releases the camera. Phones do this
    // for us eventually; doing it immediately keeps the lamp off and the
    // battery alive, and the scanner picks up again on return.
    document.addEventListener('visibilitychange', function () {
      if (document.hidden && self.running) {
        self.releaseCamera();
        self.say('info', 'Paused', 'Camera released while the app was in the background.');
      } else if (!document.hidden && self.running && !self.stream) {
        self.openCamera();
      }
    });

    window.addEventListener('pagehide', function () { self.releaseCamera(); });

    if (this.root.hasAttribute('data-scanner-autostart')) this.start();
  };

  Scanner.prototype.start = function () {
    var self = this;

    if (this.running) return;
    this.running = true;
    this.say('info', 'Starting the camera…', 'Allow camera access when the browser asks.');

    var ready = this.decoder
      ? Promise.resolve(this.decoder)
      : makeDecoder(this.jsqrUrl).then(function (decode) { self.decoder = decode; return decode; });

    ready.then(function () {
      return self.openCamera();
    }).catch(function (error) {
      self.running = false;
      self.cameraFailed(error);
    });
  };

  Scanner.prototype.openCamera = function () {
    var self = this;

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      return Promise.reject(new Error('unsupported'));
    }

    return navigator.mediaDevices.getUserMedia({
      audio: false,
      video: {
        // The rear camera, where there is a choice. "ideal" rather than
        // "exact" so a laptop with one webcam still works.
        facingMode: { ideal: 'environment' },
        width: { ideal: 1280 },
        height: { ideal: 720 }
      }
    }).then(function (stream) {
      self.stream = stream;
      self.video.srcObject = stream;
      self.video.setAttribute('playsinline', '');  // iOS, or it opens full screen
      self.video.muted = true;

      return self.video.play();
    }).then(function () {
      self.root.classList.add('is-scanning');
      if (self.startButton) self.startButton.hidden = true;
      if (self.stopButton) self.stopButton.hidden = false;
      self.offerTorch();
      self.say('info', 'Ready', 'Hold the label steady in the frame.');
      self.nextScanAt = 0;
      window.requestAnimationFrame(function (t) { self.tick(t); });
    });
  };

  Scanner.prototype.tick = function (timestamp) {
    var self = this;

    if (!this.running || !this.stream) return;

    if (timestamp >= this.nextScanAt && !this.busy && this.video.readyState >= 2) {
      this.nextScanAt = timestamp + SCAN_INTERVAL_MS;
      this.readFrame();
    }

    window.requestAnimationFrame(function (t) { self.tick(t); });
  };

  Scanner.prototype.readFrame = function () {
    var self = this;
    var w = this.video.videoWidth;
    var h = this.video.videoHeight;

    if (!w || !h) return;

    // Decode a downscaled frame. A QR code that fills a third of the view is
    // perfectly readable at 640px wide, and the smaller buffer is what keeps
    // the software decoder usable on a mid-range phone.
    var scale = Math.min(1, 640 / w);
    this.canvas.width = Math.round(w * scale);
    this.canvas.height = Math.round(h * scale);
    this.context.drawImage(this.video, 0, 0, this.canvas.width, this.canvas.height);

    var canvas = this.canvas;
    var context = this.context;
    var pixels = function () { return context.getImageData(0, 0, canvas.width, canvas.height); };

    Promise.resolve(this.decoder(canvas, pixels)).then(function (code) {
      if (code) self.found(String(code).trim());
    }).catch(function () {
      // A frame that will not decode is the normal case, not an error.
    });
  };

  Scanner.prototype.found = function (code) {
    var now = Date.now();

    if (code === '') return;

    // The same label is in frame for many frames. Only the first counts until
    // the cooldown passes; after that a deliberate rescan reaches the server.
    if (code === this.lastCode && (now - this.lastCodeAt) < SAME_CODE_COOLDOWN_MS) return;

    this.lastCode = code;
    this.lastCodeAt = now;
    this.flash();
    this.submit(code, 'CAMERA');
  };

  /* ------------------------------------------------------------- the server */

  Scanner.prototype.submit = function (code, source) {
    var self = this;

    if (this.busy) return Promise.resolve();
    this.busy = true;
    this.root.classList.add('is-busy');

    var body = new FormData();
    body.append('code', code);
    body.append('source', source);
    if (this.csrfName) body.append(this.csrfName, this.csrfToken);

    return fetch(this.endpoint, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
    }).then(function (response) {
      if (response.status === 401 || response.status === 403) {
        throw new Error('Your session has ended. Sign in again to carry on scanning.');
      }

      return response.json().catch(function () {
        throw new Error('The server replied with something unreadable.');
      });
    }).then(function (data) {
      // Every reply carries a fresh CSRF token: the application rotates it on
      // each request, so without this the second scan would be rejected.
      if (data.csrf) self.csrfToken = data.csrf;

      self.handle(code, data);
    }).catch(function (error) {
      self.report('ERROR', code, 'Not recorded', error.message || 'Could not reach the server. Check the connection and scan again.');
    }).then(function () {
      self.busy = false;
      self.root.classList.remove('is-busy');
    });
  };

  Scanner.prototype.handle = function (code, data) {
    var status = data.status || (data.ok ? 'ACCEPTED' : 'REJECTED');

    if (data.counter && this.counterBox) {
      this.counterBox.textContent = data.counter.label;
      if (typeof data.counter.done === 'number') {
        this.counterBox.setAttribute('data-done', String(data.counter.done));
      }
      if (data.counter.total) {
        this.counterBox.setAttribute('data-total', String(data.counter.total));
      }
    }

    // A page may want to do more with an accepted scan than show a line —
    // tick a checkbox on a dispatch list, for instance.
    this.root.dispatchEvent(new CustomEvent('polyfizz:scan', {
      bubbles: true,
      detail: { code: code, status: status, data: data }
    }));

    this.report(status, code, data.title || status, data.message || '', data.detail);
  };

  Scanner.prototype.report = function (status, code, title, message, detail) {
    var feedback = FEEDBACK[status] || FEEDBACK.REJECTED;

    beep(feedback.tones);
    buzz(feedback.buzz);
    this.say(feedback.tone, title, message);
    this.remember(feedback.tone, code, title, message, detail);
  };

  /* ------------------------------------------------------------ the display */

  Scanner.prototype.say = function (tone, title, message) {
    if (!this.statusBox) return;

    this.statusBox.className = 'scanner__status scanner__status--' + tone;
    this.statusBox.textContent = '';

    var strong = document.createElement('strong');
    strong.className = 'd-block';
    strong.textContent = title;
    this.statusBox.appendChild(strong);

    if (message) {
      var span = document.createElement('span');
      span.textContent = message;
      this.statusBox.appendChild(span);
    }
  };

  Scanner.prototype.remember = function (tone, code, title, message, detail) {
    if (!this.log) return;

    var item = document.createElement('li');
    item.className = 'scanner__entry scanner__entry--' + tone;

    var head = document.createElement('div');
    head.className = 'scanner__entry-head';

    var serial = document.createElement('code');
    serial.textContent = code;
    head.appendChild(serial);

    var badge = document.createElement('span');
    badge.className = 'badge text-bg-' + (tone === 'success' ? 'success' : tone === 'warning' ? 'warning' : 'danger');
    badge.textContent = title;
    head.appendChild(badge);

    item.appendChild(head);

    var line = [message];
    if (detail) {
      ['model', 'size', 'batch', 'state'].forEach(function (key) {
        if (detail[key]) line.push(detail[key]);
      });
    }
    var note = document.createElement('div');
    note.className = 'scanner__entry-note';
    note.textContent = line.filter(Boolean).join(' · ');
    item.appendChild(note);

    this.log.insertBefore(item, this.log.firstChild);

    while (this.log.children.length > LOG_LIMIT) {
      this.log.removeChild(this.log.lastChild);
    }
  };

  Scanner.prototype.flash = function () {
    if (!this.frame) return;
    var frame = this.frame;
    frame.classList.add('is-hit');
    window.setTimeout(function () { frame.classList.remove('is-hit'); }, 180);
  };

  /* ----------------------------------------------------------- the hardware */

  Scanner.prototype.offerTorch = function () {
    if (!this.torchButton || !this.stream) return;

    var track = this.stream.getVideoTracks()[0];
    var capabilities = track && track.getCapabilities ? track.getCapabilities() : {};

    this.torchButton.hidden = !capabilities.torch;
  };

  Scanner.prototype.toggleTorch = function () {
    if (!this.stream) return;

    var track = this.stream.getVideoTracks()[0];
    var self = this;

    track.applyConstraints({ advanced: [{ torch: !this.torchOn }] }).then(function () {
      self.torchOn = !self.torchOn;
      self.torchButton.setAttribute('aria-pressed', self.torchOn ? 'true' : 'false');
      self.torchButton.classList.toggle('active', self.torchOn);
    }).catch(function () {
      self.torchButton.hidden = true;
    });
  };

  Scanner.prototype.releaseCamera = function () {
    if (this.stream) {
      this.stream.getTracks().forEach(function (track) { track.stop(); });
      this.stream = null;
    }
    if (this.video) this.video.srcObject = null;
    this.torchOn = false;
    this.root.classList.remove('is-scanning');
  };

  Scanner.prototype.stop = function (message) {
    this.running = false;
    this.releaseCamera();
    if (this.startButton) this.startButton.hidden = false;
    if (this.stopButton) this.stopButton.hidden = true;
    if (this.torchButton) this.torchButton.hidden = true;
    this.say('info', 'Camera off', message || '');
  };

  /*
   * Every reason the camera can refuse, said plainly, with the way round it.
   * Typing stays available throughout, so a refusal slows the job down but
   * never stops it.
   */
  Scanner.prototype.cameraFailed = function (error) {
    var name = (error && error.name) || '';
    var title = 'Camera unavailable';
    var message;

    if (name === 'NotAllowedError' || name === 'SecurityError') {
      title = 'Camera blocked';
      message = 'Permission was refused. Allow camera access for this site in the browser settings '
        + '(tap the padlock in the address bar), then press Scan again. You can type a serial below in the meantime.';
    } else if (name === 'NotFoundError' || name === 'OverconstrainedError') {
      title = 'No camera found';
      message = 'This device has no camera the browser can use. Type the serial below instead.';
    } else if (name === 'NotReadableError' || name === 'AbortError') {
      title = 'Camera busy';
      message = 'Another app is using the camera. Close it and press Scan again, or type the serial below.';
    } else if (error && error.message === 'unsupported') {
      message = 'This browser cannot open a camera. Camera scanning needs a secure (https) address and a '
        + 'recent browser. Type the serial below instead.';
    } else if (error && error.message === 'decoder did not load') {
      title = 'Scanner files missing';
      message = 'The QR reader could not be loaded. Reload the page; if it keeps happening, tell your administrator. '
        + 'Typing a serial still works.';
    } else {
      message = 'The camera could not be started. Type the serial below, and try Scan again later.';
    }

    this.say('danger', title, message);
    if (this.startButton) this.startButton.hidden = false;
    if (this.stopButton) this.stopButton.hidden = true;

    var input = this.manualForm && this.manualForm.querySelector('input[name="code"]');
    if (input) input.focus();
  };

  /* ------------------------------------------------------------------ setup */

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-scanner]').forEach(function (root) {
      root.scanner = new Scanner(root);
    });
  });
})();
