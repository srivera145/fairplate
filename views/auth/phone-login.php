<?php
use EchoDial\Deck\Deck;
use Keel\Core\Theme;
?>
<!DOCTYPE html>
<html <?= Deck::htmlAttributes(lang: 'en') ?> <?= Deck::theme(mode: Theme::serverPreference()) ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
<script src="/js/fairplate-auth.js" defer></script>
</head>
<body>
    <span id="top" tabindex="-1"></span>

    <main class="container stage" style="--stage-width: 26rem">
        <section class="card">
            <div class="card-body stack stack-6" id="phone-auth">
                <div class="bar">
                    <div class="stack stack-0">
                        <h1 class="h4">Sign in to FairPlate</h1>
                        <p class="text-sm text-muted">We will text you a code. No password.</p>
                    </div>
                    <?php $themeToggleClass = 'push'; require __DIR__ . '/../partials/theme-toggle.php'; ?>
                </div>

                <div id="phone-step" class="stack stack-4">
                    <div class="field">
                        <label class="label" for="phone">Mobile number</label>
                        <input type="tel" id="phone" class="input" autocomplete="tel" inputmode="tel" placeholder="(850) 555-0123">
                    </div>
                    <button type="button" id="phone-send" class="btn btn-primary btn-block">Send code</button>
                </div>

                <div id="code-step" class="stack stack-4" hidden>
                    <div class="field">
                        <label class="label" for="code">Enter the 6-digit code</label>
                        <input type="text" id="code" class="input otp-input" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="000000">
                    </div>
                    <button type="button" id="phone-verify" class="btn btn-primary btn-block">Verify</button>
                </div>

                <p id="auth-error" class="error" hidden></p>
            </div>
        </section>
    </main>
</body>
</html>
