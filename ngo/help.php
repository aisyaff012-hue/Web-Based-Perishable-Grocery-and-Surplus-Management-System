<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('ngo');

$pageTitle = 'Help';
$activePage = 'help';

require __DIR__ . '/../includes/layouts/header.php';
?>

<section class="section-head">
    <div>
        <h2>Help</h2>
        <p>How FreshTrack works and what each page is for</p>
    </div>
</section>

<div class="help-intro">
    <h3>How FreshTrack works</h3>

    <p>Merchants list their perishable stock. As an item approaches its expiry date, the system reduces its value and eventually marks it as surplus. Surplus food appears here for your organisation to reserve, free of charge.</p>

    <p>You reserve what you can collect, the merchant approves it, and you pick it up before the expiry date.</p>
</div>

<div class="help-list">

    <details class="help-item" open>
        <summary>Getting started</summary>

        <div class="help-body">
            <p>Saving your location lets the system sort merchants by distance and place you on the map.</p>

            <ol class="help-steps">
                <li>Open <strong>Profile</strong> and click <strong>Edit Profile</strong>.</li>
                <li>Open Google Maps, right-click your office, and click the numbers that appear. Paste the first into <strong>Latitude</strong> and the second into <strong>Longitude</strong>.</li>
                <li>Click <strong>Save Changes</strong>.</li>
            </ol>

            <p class="help-note">Without coordinates you can still browse and reserve, but distances and the map will not be available.</p>
        </div>
    </details>

    <details class="help-item">
        <summary>Finding surplus food</summary>

        <div class="help-body">
            <p>There are two ways to look.</p>

            <ul class="help-bullets">
                <li><strong>Available Surplus</strong> lists merchants who currently have food, nearest first. Click one to see everything they have.</li>
                <li><strong>Map</strong> shows the same merchants as pins. Click <strong>Nearest Merchant</strong> to jump to the closest one.</li>
            </ul>

            <p>Use the search box, category filter or distance filter to narrow the list.</p>

            <p>On the map, pin colour shows urgency: green means three days left, orange two days, and red one day or less.</p>
        </div>
    </details>

    <details class="help-item">
        <summary>Making a reservation</summary>

        <div class="help-body">
            <ol class="help-steps">
                <li>Open a merchant and click <strong>Reserve Item</strong> on the product you want.</li>
                <li>Enter the quantity. It cannot exceed what is available.</li>
                <li>Choose a preferred pickup date, on or before the expiry date.</li>
                <li>Add a note for the merchant if useful, then click <strong>Submit Request</strong>.</li>
            </ol>

            <p class="help-note">All surplus food is free. The prices shown are the market value of the food, used to measure how much waste was avoided.</p>
        </div>
    </details>

    <details class="help-item">
        <summary>What each status means</summary>

        <div class="help-body">
            <table class="help-table">
                <tbody>
                    <tr>
                        <td><span class="badge badge-pending">Pending</span></td>
                        <td>Waiting for the merchant to decide. You can still cancel.</td>
                    </tr>

                    <tr>
                        <td><span class="badge badge-approved">Approved</span></td>
                        <td>Accepted. Go to the merchant on the agreed date.</td>
                    </tr>

                    <tr>
                        <td><span class="badge badge-completed">Completed</span></td>
                        <td>Collected and confirmed by the merchant.</td>
                    </tr>

                    <tr>
                        <td><span class="badge badge-rejected">Rejected</span></td>
                        <td>Declined. The merchant's reason appears beside the status.</td>
                    </tr>

                    <tr>
                        <td><span class="badge badge-cancelled">Cancelled</span></td>
                        <td>Withdrawn by you before the merchant decided.</td>
                    </tr>

                    <tr>
                        <td><span class="badge badge-not_collected">Not Collected</span></td>
                        <td>The product expired before you collected it. Closed automatically.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </details>

    <details class="help-item">
        <summary>Collecting your food</summary>

        <div class="help-body">
            <ol class="help-steps">
                <li>Open <strong>Pickup Schedule</strong> to see every approved collection.</li>
                <li>Check the pickup address, contact number and opening hours.</li>
                <li>Click <strong>Map</strong> for directions.</li>
                <li>Collect the food. The merchant confirms it in their own account, and the status becomes Completed.</li>
            </ol>

            <p class="help-note">If you cannot make it, cancel the reservation rather than leaving it. The stock returns to the pool and another organisation can take it.</p>
        </div>
    </details>

    <details class="help-item">
        <summary>Cancelling a reservation</summary>

        <div class="help-body">
            <ol class="help-steps">
                <li>Open <strong>My Reservations</strong> and find the pending request.</li>
                <li>Click <strong>Cancel</strong> and confirm.</li>
            </ol>

            <p>The stock returns immediately to the merchant's available surplus.</p>

            <p class="help-note">Only pending requests can be cancelled. Once a merchant has approved or rejected a request, that decision stays on record.</p>
        </div>
    </details>

    <details class="help-item">
        <summary>Your collection rate</summary>

        <div class="help-body">
            <p>The Reports page shows a collection rate. It counts reservations that reached an outcome within your control:</p>

            <ul class="help-bullets">
                <li><strong>Counted as success</strong> — reservations you collected</li>
                <li><strong>Counted against you</strong> — reservations left until the food expired</li>
                <li><strong>Not counted</strong> — rejections by the merchant, and cancellations you made early</li>
            </ul>

            <p>Cancelling early is not penalised, because it releases the stock for another organisation. Leaving a reservation until the food expires is, because nobody else could take it.</p>
        </div>
    </details>

    <details class="help-item">
        <summary>Reports and exporting</summary>

        <div class="help-body">
            <ol class="help-steps">
                <li>Open <strong>Reports</strong>.</li>
                <li>Set a date range and category if you want a narrower report, then click <strong>Apply</strong>.</li>
                <li>Click <strong>Export CSV</strong> for a spreadsheet, or <strong>Print / PDF</strong> for a printable report.</li>
            </ol>

            <p class="help-note">If your spreadsheet shows ###### instead of dates, the column is too narrow. Double-click the column edge to widen it.</p>
        </div>
    </details>

    <details class="help-item">
        <summary>Something went wrong</summary>

        <div class="help-body">
            <table class="help-table">
                <tbody>
                    <tr>
                        <td class="help-problem">No distances are shown</td>
                        <td>Your organisation has no coordinates saved. Add them in Profile.</td>
                    </tr>

                    <tr>
                        <td class="help-problem">The map is empty</td>
                        <td>No merchant with surplus has saved coordinates yet, or there is no surplus available right now.</td>
                    </tr>

                    <tr>
                        <td class="help-problem">The Reserve button is missing</td>
                        <td>The item is fully reserved by other organisations, or it has expired.</td>
                    </tr>

                    <tr>
                        <td class="help-problem">A merchant disappeared from the list</td>
                        <td>Their surplus has all been reserved or collected. They will reappear when they have stock again.</td>
                    </tr>

                    <tr>
                        <td class="help-problem">A reservation closed by itself</td>
                        <td>The item expired before collection. It is recorded as Not Collected.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </details>

</div>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
