<?= $this->extend('layouts/site') ?>
<?= $this->section('content') ?>
<?= view('partials/breadcrumbs', ['trail' => $trail]) ?>
<section class="section-tight">
    <div class="container container-narrow prose">
        <h1>Privacy policy</h1>
        <p class="text-muted-ink">This policy describes what this website and the <?= esc(brand('shortName')) ?> platform collect, why, and how long it is kept. It is written to match what the system does.</p>

        <div class="alert alert-warning my-4"><strong>Before launch:</strong> have this reviewed against the Digital Personal Data Protection Act and add your grievance officer’s name and contact details.</div>

        <h2 class="mt-5">What this website collects</h2>
        <p class="text-muted-ink">If you send an enquiry or apply for a dealership, we store the details you enter: your name, phone number, email address, city and your message. We also store a one-way hash of your IP address and your browser’s user-agent string, to limit automated abuse of the forms. We do not store the IP address itself.</p>
        <p class="text-muted-ink">If analytics is enabled, Google Analytics 4 is loaded with IP anonymisation on and advertising signals off. If no measurement ID is configured, no analytics script is loaded at all.</p>

        <h2 class="mt-5">Warranty verification</h2>
        <p class="text-muted-ink">Checking a serial number does not require an account and does not identify you. The result shows the product, its manufacturing date and its warranty status. It never shows the customer, the dealer, the price or any claim history.</p>

        <h2 class="mt-5">What your dealer records</h2>
        <p class="text-muted-ink">When you buy a <?= esc(brand('shortName')) ?> mattress, your dealer records the sale against the mattress’s serial number, along with your name and contact details, so that the warranty can be honoured. Contact details are encrypted in our database. Your dealer can see their own customer records; other dealers cannot.</p>

        <h2 class="mt-5">How long we keep it</h2>
        <p class="text-muted-ink">Enquiries are kept while they are being handled and for a reasonable period afterwards. Sale and warranty records are kept for the life of the warranty and for as long as we are required to retain them, because they are the evidence behind a warranty obligation.</p>

        <h2 class="mt-5">Your rights</h2>
        <p class="text-muted-ink">You can ask what we hold about you, ask for it to be corrected, and ask for it to be deleted where we are not required to keep it. Write to the support address in the footer and we will respond.</p>
    </div>
</section>
<?= $this->endSection() ?>
