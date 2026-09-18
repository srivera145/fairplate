<?php
/**
 * One offer, on its own page.
 *
 * The same card the home screen shows, reached by URL so that a driver coming
 * back to the app from a notification lands on the thing itself. It runs the
 * same script, so the countdown ring still counts and an offer that is answered
 * elsewhere still takes them onward.
 */

use Keel\Core\View;

$driveScripts = ['/js/drive.js'];
$card = $card ?? null;

require __DIR__ . '/partials/top.php';
?>

<div id="drive-config"
     hidden
     data-online="1"
     data-offer-url="/drive/offers/current"
     data-location-url="/drive/location"
     data-offer-poll-seconds="<?= (int) ($offerPollSeconds ?? 3) ?>"
     data-ping-seconds="<?= (int) ($onlinePingSeconds ?? 15) ?>"></div>

<div class="offer-slot" data-offer-slot>
    <?php if ($card !== null) { View::render('drive.partials.offer', $card); } ?>
</div>

<?php require __DIR__ . '/partials/bottom.php'; ?>
