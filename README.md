# FroshAltchaCaptcha

Self-hosted Altcha Captcha plugin for Shopware 6. Adds secure, user-friendly bot protection without relying on third-party services.

## Features

- **Self-Hosted**: No external dependencies or third-party services required
- **Privacy-First**: Fully compliant with GDPR and privacy regulations
- **Lightweight**: Minimal performance impact on your shop
- **User-Friendly**: Better user experience compared to traditional CAPTCHAs
- **Easy Integration**: Works seamlessly with Shopware 6 forms

## Installation

### Via Composer

```bash
composer require frosh/altcha-captcha
```

```bash
bin/console plugin:refresh
bin/console plugin:install --activate FroshAltchaCaptcha
bin/console cache:clear
```

## Configuration

After installation, configure the plugin in the Shopware administration:

1. Navigate to **Settings > General > Basic Information**
2. Scroll down to the **CAPTCHA** section
3. Select **AltchaCaptcha** under **Active CAPTCHAS**
4. Configure your Altcha settings according to your needs below the selection

## How It Works

Altcha is a modern, privacy-focused CAPTCHA alternative that uses proof-of-work challenges to verify that form submissions come from real users, not bots. Unlike traditional CAPTCHAs:

- No image recognition required
- No personal data collection
- No tracking cookies
- Fully self-hosted solution

## Development

### Project Structure

```
src/
├── Controller/
│   └── AltchaController.php       # API endpoints for CAPTCHA verification
├── Migration/
│   └── Migration1763454351...php  # Database setup
├── Storefront/
│   └── Framework/
│       └── AltchaCaptcha.php      # Core CAPTCHA logic
└── FroshAltchaCaptcha.php        # Plugin bootstrap
```

## Support

- **GitHub Issues**: [https://github.com/FriendsOfShopware/FroshAltchaCaptcha/issues](https://github.com/FriendsOfShopware/FroshAltchaCaptcha/issues)

## License

This plugin is licensed under the [MIT License](LICENSE).

## Credits

- Developed by [tinect](https://github.com/tinect/)
- Powered by [Altcha](https://altcha.org/)

**Note**: This plugin is an independent implementation and the developer (tinect) is not associated with or endorsed by ALTCHA.

## About Altcha

Altcha is an open-source, self-hosted CAPTCHA alternative that provides bot protection without compromising user privacy or experience. Learn more at [altcha.org](https://altcha.org/).

## Replay protection (single-use challenges)

Altcha verifies a submission's signature, proof of work and expiry, but not whether
the solved challenge has been used before. Without that check a single solved token is
accepted on every submission until it expires — one proof of work buys unlimited
requests inside the validity window.

This plugin enforces single use. On the first accepted submission the challenge
signature is recorded in `frosh_altcha_captcha_used_challenge`; any later submission
carrying the same signature is rejected. A `UNIQUE` key makes the first write win even
under concurrent submissions, expired rows are purged opportunistically, and a storage
failure fails open (logged) so a database outage cannot lock every protected form.

The challenge validity window (`AltchaController`) is kept short as defense in depth;
tune it together with `cost`/`counter` for the balance of bot cost versus latency your
shop needs.
