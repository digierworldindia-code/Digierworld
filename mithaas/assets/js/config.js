/* ==========================================================================
   MITHAAS — Business configuration
   --------------------------------------------------------------------------
   THIS IS THE ONLY FILE YOU EDIT TO PUBLISH REAL BUSINESS DETAILS.

   Every value below that is `null` has NOT been supplied by the business yet.
   Nothing is invented. Wherever a value is null the website renders an
   honest "to be confirmed" state instead of a made-up phone number,
   address, price or social handle.

   Fill a value in, save, reload — it appears everywhere on the site.
   ========================================================================== */

window.MITHAAS = {

  /* ---- Identity --------------------------------------------------------- */
  name: "Mithaas",
  legalName: null,               // e.g. "Mithaas Sweets & Restaurant"
  descriptor: "Sweets • Bakery • Restaurant",

  /* Absolute site root, used for canonical URLs and structured data.
     Change this to the live domain before launch. */
  origin: "https://www.digierworld.com/mithaas",

  /* ---- Contact ---------------------------------------------------------- */
  // Store phone numbers in full international form: "+919999999999"
  phone: null,
  phoneDisplay: null,            // e.g. "+91 99999 99999"
  whatsapp: null,                // digits only, with country code: "919999999999"
  email: null,

  /* ---- Location --------------------------------------------------------- */
  address: {
    line1: null,                 // e.g. "Shop 4, Krishna Complex"
    line2: null,                 // e.g. "Alwar Bypass Road"
    landmark: null,              // e.g. "Opposite City Park"
    locality: null,              // e.g. "Bhiwadi"
    region: null,                // e.g. "Rajasthan"
    postalCode: null,
    country: "IN"
  },
  geo: { lat: null, lng: null },

  /* Paste the src="..." value from a Google Maps > Share > Embed a map iframe */
  mapEmbed: null,
  /* Paste the Google Maps share link (or a maps.app.goo.gl short link) */
  mapLink: null,

  /* ---- Opening hours ----------------------------------------------------
     Use 24-hour "HH:MM" strings. Set a day to null when closed.
     Example: mon: { open: "08:30", close: "22:30" }                        */
  hours: {
    mon: null, tue: null, wed: null, thu: null,
    fri: null, sat: null, sun: null
  },
  hoursNote: null,               // e.g. "Kitchen closes 30 minutes before shop"

  /* ---- Social ----------------------------------------------------------- */
  social: {
    instagram: null,             // full URL
    instagramHandle: null,       // e.g. "@mithaas"
    facebook: null,
    // WhatsApp link is generated from `whatsapp` above
    google: null                 // Google Business profile / reviews link
  },

  /* ---- Pricing ----------------------------------------------------------
     Prices are intentionally absent. The menu renders a discreet
     "Price on request" until real Mithaas prices are supplied in
     menu-data.js. Set this to true once every price is filled in.        */
  showPrices: false,
  currency: "₹",

  /* ---- Reviews ----------------------------------------------------------
     Leave empty until real, attributable customer reviews are available.
     Shape: { text: "…", author: "…", rating: 5, source: "Google" }        */
  testimonials: [],

  /* ---- Enquiry routing --------------------------------------------------
     "whatsapp" — opens WhatsApp with the enquiry pre-filled (no backend)
     "email"    — opens the visitor's mail client
     "endpoint" — POSTs JSON to `formEndpoint` (Formspree, Basin, your API) */
  formMode: "whatsapp",
  formEndpoint: null
};
