<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('merchant');

/*
 * Ambang (threshold) yang dipaparkan dalam panduan ni dibaca dari
 * tetapan merchant sendiri, bukan hard-code, supaya teks panduan
 * kekal betul selepas merchant tukar peraturan dia.
 */
$settings = getMerchantSettings($pdo, $_SESSION['user_id']);

$nearExpiry = (int) $settings['near_expiry_days'];
$donation = (int) $settings['donation_threshold_days'];
$reduction = rtrim(rtrim(number_format((float) $settings['reduction_percentage'], 2), '0'), '.');

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

    <p>You list your perishable stock. The system watches each product's expiry date and reduces its value as the date approaches. Once a product is close enough to expiry, it becomes surplus automatically and registered NGOs can reserve it free of charge.</p>

    <p>You never mark anything as surplus yourself. Your part is to keep the inventory accurate and to approve the requests that come in.</p>
</div>

<div class="help-list">

    <details class="help-item" open>
        <summary>Getting started</summary>

        <div class="help-body">
            <p>Two things need to be in place before NGOs can find you.</p>

            <ol class="help-steps">
                <li>Open <strong>Profile</strong> and click <strong>Edit Profile</strong>. Fill in your pickup location and operating hours.</li>
                <li>Add your coordinates. Open Google Maps, right-click your shop, and click the numbers that appear. Paste the first into <strong>Latitude</strong> and the second into <strong>Longitude</strong>.</li>
                <li>Upload a shop photo if you have one. Landscape photographs look best.</li>
            </ol>

            <p class="help-note">Without coordinates your shop will not appear on the NGO map, and NGOs cannot see how far away you are.</p>
        </div>
    </details>

    <details class="help-item">
        <summary>Adding and managing inventory</summary>

        <div class="help-body">
            <ol class="help-steps">
                <li>Go to <strong>My Listings</strong> and click <strong>Add New Product</strong>.</li>
                <li>Enter the product name, category, quantity, unit, base price and expiry date.</li>
                <li>Upload a product photo if you wish. A category icon is used when none is uploaded.</li>
                <li>Click <strong>Save Product</strong>.</li>
            </ol>

            <p>You do not choose the status or the reduced price. Both are worked out from the expiry date.</p>

            <p class="help-note">Quantity cannot be reduced below the amount NGOs have already reserved. If you try, the system tells you the minimum allowed.</p>
        </div>
    </details>

    <details class="help-product">
        <summary>How product status is decided</summary>

        <div class="help-body">
            <p>Each product is reclassified automatically. These are your current rules, which you can change in <strong>Settings</strong>.</p>

            <table class="help-table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Days to expiry</th>
                        <th>Value</th>
                        <th>Meaning</th>
                    </tr>
                </thead>

                <tbody>
                    <tr>
                        <td><span class="badge badge-available">Available</span></td>
                        <td>More than <?= $nearExpiry ?></td>
                        <td>Full base price</td>
                        <td>Normal stock</td>
                    </tr>

                    <tr>
                        <td><span class="badge badge-near_expiry">Near Expiry</span></td>
                        <td><?= $nearExpiry ?> to <?= $donation + 1 ?></td>
                        <td>Reduced by <?= $reduction ?>%</td>
                        <td>Approaching the donation window</td>
                    </tr>

                    <tr>
                        <td><span class="badge badge-surplus">Surplus</span></td>
                        <td><?= $donation ?> or fewer</td>
                        <td>Reduced by <?= $reduction ?>%</td>
                        <td>Visible to NGOs, free to collect</td>
                    </tr>

                    <tr>
                        <td><span class="badge badge-expired">Expired</span></td>
                        <td>Past the date</td>
                        <td>No value</td>
                        <td>Removed from the surplus list; uncollected quantity recorded as waste</td>
                    </tr>
                </tbody>
            </table>

            <p class="help-note">The check runs every few minutes, so a newly expired product may take a moment to update.</p>
        </div>
    </details>

    <details class="help-item">
        <summary>Handling NGO requests</summary>

        <div class="help-body">
            <ol class="help-steps">
                <li>Open <strong>Requests</strong>. New ones sit under the <strong>Pending</strong> tab.</li>
                <li>Check the NGO, product, quantity and preferred pickup date.</li>
                <li>Click <strong>Approve</strong> to accept, or <strong>Reject</strong> to decline.</li>
                <li>If rejecting, write a short reason. The NGO will see it.</li>
                <li>When the NGO arrives and takes the food, open the <strong>Approved</strong> tab and click <strong>Mark Collected</strong>.</li>
            </ol>

            <p class="help-note">A reservation only counts towards your impact figures once you mark it collected.</p>
        </div>
    </details>

    <details class="help-item">
        <summary>Reading the Donations page</summary>

        <div class="help-body">
            <p>Your surplus is grouped into four tabs.</p>

            <ul class="help-bullets">
                <li><strong>Available</strong> — not yet reserved by anyone</li>
                <li><strong>Reserved</strong> — has a pending or approved reservation</li>
                <li><strong>Collected</strong> — picked up successfully</li>
                <li><strong>Expired</strong> — passed the expiry date with some quantity uncollected</li>
            </ul>

            <p>An product can appear in more than one tab. A batch that was half collected and half wasted shows under both Collected and Expired, because both facts are true.</p>
        </div>
    </details>

    <details class="help-item">
        <summary>Changing your pricing rules</summary>

        <div class="help-body">
            <ol class="help-steps">
                <li>Open <strong>Settings</strong>.</li>
                <li>Adjust the near-expiry threshold, the donation threshold or the reduction percentage.</li>
                <li>Click <strong>Save Settings</strong>.</li>
            </ol>

            <p>Every product is reclassified on the next system check, so changing the donation threshold can move products into or out of surplus straight away.</p>
        </div>
    </details>

    <details class="help-item">
        <summary>Reports and exporting</summary>

        <div class="help-body">
            <ol class="help-steps">
                <li>Open <strong>Reports</strong>.</li>
                <li>Set a start date, an end date and a category, then click <strong>Apply</strong> to narrow the report.</li>
                <li>Click <strong>Export CSV</strong> for a spreadsheet of the filtered records.</li>
                <li>Click <strong>Print / PDF</strong>, then choose <em>Save as PDF</em> in your browser's print dialogue.</li>
                <li>Click <strong>Reset</strong> to return to the full report.</li>
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
                        <td class="help-problem">My shop is not on the NGO map</td>
                        <td>Open Profile and check that both latitude and longitude are saved. Both are needed.</td>
                    </tr>

                    <tr>
                        <td class="help-problem">A product did not become surplus</td>
                        <td>The check runs every few minutes. Wait a moment and refresh the page.</td>
                    </tr>

                    <tr>
                        <td class="help-problem">I cannot reduce a quantity</td>
                        <td>NGOs have already reserved that amount. The message shows the minimum you can set.</td>
                    </tr>

                    <tr>
                        <td class="help-problem">A product shows 0 available but still has stock</td>
                        <td>All of it is reserved or already collected. The listing shows both the original quantity and what is left.</td>
                    </tr>

                    <tr>
                        <td class="help-problem">A reservation closed by itself</td>
                        <td>The product expired before the NGO collected it. The system closes it as Not Collected and records the waste.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </details>

</div>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>