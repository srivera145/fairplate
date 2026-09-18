<?php
/**
 * Opening hours, holiday closures, and the Pause Orders switch.
 *
 * Each day gets a fixed number of range slots rather than an "add another"
 * button. Three is enough for breakfast, lunch and dinner, an empty slot is how
 * a range is removed, and the whole editor then needs no JavaScript at all —
 * which matters on the one screen an owner may be using at six in the morning
 * on a tablet that has not been updated since it was bought.
 */

use EchoDial\Deck\Deck;
use Keel\App\Models\Restaurant;
use Keel\App\Services\RestaurantHours;
use Keel\Core\Csrf;

require __DIR__ . '/partials/top.php';

$hours = $hours ?? RestaurantHours::normalize(null);
$errors = $errors ?? [];
$paused = $paused ?? false;
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$slots = RestaurantHours::MAX_RANGES_PER_DAY;

// Two spare rows, so adding a holiday needs no second visit.
$closures = array_merge($hours['closures'], [
    ['date' => '', 'label' => ''],
    ['date' => '', 'label' => ''],
]);
?>

<section class="card <?= $paused ? '' : 'card-brand' ?>">
    <div class="card-header">
        <h2 class="card-title">Pause orders</h2>
    </div>
    <div class="card-body stack stack-3">
        <?php if ($paused): ?>
        <p>
            New orders are not reaching this kitchen.
            <?php $left = Restaurant::pauseMinutesLeft($restaurant); ?>
            <?= $left === null
                ? 'They will stay paused until you turn them back on.'
                : 'They start again on their own in ' . (int) $left . ' minutes.' ?>
        </p>
        <form method="POST" action="/kitchen/resume">
            <?= Csrf::field() ?>
            <input type="hidden" name="back" value="/kitchen/hours">
            <button type="submit" class="btn btn-primary btn-lg btn-block">
                <?= Deck::icon('check') ?> Take orders again
            </button>
        </form>
        <?php else: ?>
        <p class="text-muted">
            Stops new orders reaching the board. Orders you have already accepted are not affected.
        </p>
        <form method="POST" action="/kitchen/pause" class="order-choices">
            <?= Csrf::field() ?>
            <input type="hidden" name="back" value="/kitchen/hours">
            <?php foreach (Restaurant::PAUSE_MINUTES as $minutes): ?>
            <button type="submit" name="minutes" value="<?= $minutes ?>" class="btn btn-lg btn-outline">
                Pause <?= $minutes ?> min
            </button>
            <?php endforeach; ?>
            <button type="submit" name="minutes" value="until_resumed" class="btn btn-lg btn-danger">
                Pause until I resume
            </button>
        </form>
        <p class="text-sm text-muted">A timed pause lifts itself — nobody has to remember to come back.</p>
        <?php endif; ?>
    </div>
</section>

<?php if ($errors !== []): ?>
<div class="alert alert-bad">
    <p class="alert-title">Your hours were not saved</p>
    <ul class="alert-body">
        <?php foreach ($errors as $message): ?>
        <li><?= $escape((string) $message) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="/kitchen/hours" class="stack stack-5">
    <?= Csrf::field() ?>

    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Opening hours</h2>
        </div>
        <div class="card-body stack stack-4">
            <p class="text-sm text-muted">
                Times are local. Leave a row blank to remove it; leave a whole day blank to close that day.
                A range that ends before it starts runs past midnight.
            </p>

            <?php foreach (RestaurantHours::DAYS as $day): ?>
            <?php $ranges = $hours['days'][$day]; ?>
            <fieldset class="fieldset">
                <legend>
                    <?= $escape(RestaurantHours::DAY_LABELS[$day]) ?>
                    <span class="text-sm text-muted">— <?= $escape(RestaurantHours::describeDay($ranges)) ?></span>
                </legend>
                <div class="stack stack-2">
                    <?php for ($slot = 0; $slot < $slots; $slot++): ?>
                    <?php $range = $ranges[$slot] ?? ['open' => '', 'close' => '']; ?>
                    <div class="field-row">
                        <div class="field">
                            <label class="label" for="open-<?= $day ?>-<?= $slot ?>">Opens</label>
                            <input type="time" id="open-<?= $day ?>-<?= $slot ?>"
                                   name="open[<?= $day ?>][]" class="input"
                                   value="<?= $escape((string) $range['open']) ?>">
                        </div>
                        <div class="field">
                            <label class="label" for="close-<?= $day ?>-<?= $slot ?>">Closes</label>
                            <input type="time" id="close-<?= $day ?>-<?= $slot ?>"
                                   name="close[<?= $day ?>][]" class="input"
                                   value="<?= $escape((string) $range['close']) ?>">
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
            </fieldset>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Holiday closures</h2>
        </div>
        <div class="card-body stack stack-3">
            <p class="text-sm text-muted">
                A whole day closed, whatever your usual hours say. Clear the date to drop one.
            </p>
            <?php foreach ($closures as $index => $closure): ?>
            <div class="field-row">
                <div class="field">
                    <label class="label" for="closure-date-<?= $index ?>">Date</label>
                    <input type="date" id="closure-date-<?= $index ?>" name="closure_date[]" class="input"
                           value="<?= $escape((string) $closure['date']) ?>">
                </div>
                <div class="field">
                    <label class="label" for="closure-label-<?= $index ?>">What for <span class="optional">Optional</span></label>
                    <input type="text" id="closure-label-<?= $index ?>" name="closure_label[]" class="input"
                           placeholder="Thanksgiving" value="<?= $escape((string) $closure['label']) ?>">
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="form-actions form-actions-sticky">
        <button type="submit" class="btn btn-primary btn-lg">Save hours</button>
    </div>
</form>

<?php require __DIR__ . '/partials/bottom.php'; ?>
