<?php
/**
 * The top of every driver screen.
 *
 * Set $title, $heading, $activeNav, $user, $driver and $flash before requiring
 * it — Drive\DriverController::shell() returns exactly that. A screen that needs
 * a script of its own lists it in $driveScripts before requiring this.
 *
 * Deliberately smaller than the customer app's header. A driver is holding the
 * phone in one hand, at arm's length, in daylight, and every row of chrome at
 * the top is a row the Accept button has to be pushed down by. There is a
 * heading, a status dot and nothing else; the theme switch and sign out live on
 * the earnings screen, which is the one screen nobody is on mid-run.
 */

use EchoDial\Deck\Deck;
use Keel\App\Models\Driver;
use Keel\Core\Theme;

$activeNav = $activeNav ?? 'home';
$flash = $flash ?? null;
$driver = $driver ?? null;
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$isApproved = Driver::isApproved($driver);
$isOnline = $isApproved && Driver::isOnline($driver);
?>
<!DOCTYPE html>
<html <?= Deck::htmlAttributes(lang: 'en') ?> <?= Deck::theme(mode: Theme::serverPreference()) ?>>
<head>
<?php require dirname(__DIR__, 2) . '/partials/head.php'; ?>
<link rel="stylesheet" href="/css/drive.css">
<?php foreach (($driveScripts ?? []) as $driveScript): ?>
<script src="<?= $escape($driveScript) ?>" defer></script>
<?php endforeach; ?>
</head>
<body class="drive-body">
    <span id="top" tabindex="-1"></span>

    <main class="container stack stack-4 drive-main" style="--container: 34rem">
        <header class="drive-header bar gap-2">
            <div class="stack stack-0 min-w-0">
                <a href="/drive" class="drive-brand no-underline"><?= $escape($heading ?? 'Drive') ?></a>
                <p class="text-sm text-muted drive-status">
                    <span class="drive-dot <?= $isOnline ? 'is-online' : '' ?>" aria-hidden="true"></span>
                    <?php
                    // Three states, not two: somebody who has not applied has not
                    // been turned down, and telling them they are "not approved"
                    // would read as a refusal rather than a to-do.
                    ?>
                    <?php if ($driver === null): ?>
                    Not set up
                    <?php elseif (!$isApproved): ?>
                    Waiting for approval
                    <?php else: ?>
                    <?= $isOnline ? 'Online' : 'Offline' ?>
                    <?php endif; ?>
                </p>
            </div>
        </header>

        <?php if ($flash !== null): ?>
        <div class="alert alert-<?= $escape((string) $flash['tone']) ?>" role="status">
            <p class="alert-body"><?= $escape((string) $flash['message']) ?></p>
        </div>
        <?php endif; ?>
