=== Notakey Provider for Two-Factor ===
Contributors: notakey
Tags: two factor, two step, authentication, login, totp, notakey
Requires at least: 4.3
Tested up to: 6.0
Requires PHP: 5.6
Stable tag: 1.0.17
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reduce friction and improve security of Two-Factor Authentication using push-based Notakey Authenticator mobile application.

== Description ==

Users with enabled Notakey authentication provider will be sent authentication request to registered mobile device (phone, tablet) after entering a valid username and password.

This plugin requires Wordpress [Two-Factor](https://wordpress.org/plugins/two-factor/) plugin, that provides base authentication framework. After installing and configuring both plugins, an additional authentication provider will be added to provider list. To register a mobile device for Notakey authentication, use the "Two-Factor Options" section under "Users" → "Your Profile". Install "Notakey Authenticator" and scan provided QR code with Notakey app and enter required details for device registeration.

Notakey authentication can be combined with other second factor authentication means: TOTP, email, backup codes and others.

This plugin adds also some basic security policy options for Wordpress site admins:

- Enable 2FA provider override list - allows users to select only specified authentication providers.

- Enable Notakey 2FA provider for all users - enables Notakey authentication for all users (in case your users have devices already registered on other services).

- Allow users to provide onboarding details - lets users enter their mobile number, if SMS code verification onboarding is used.

- Reject user login without 2FA verification - blocks user login without any second factor authentication.

Other configuration options include:

- Various options to customize authentication request.

- Configuration for Notakey Authentication Server.

To adjust policy or configure this plugin, navigate to "Settings" → "Notakey MFA".

Plugin requires a hosted or on-premise version of Notakey Authentication Server (yes, there is a free version) with configured service, onboarding requirements and API client credentials.

See our [documentation site](https://documentation.notakey.com/) for detailed instructions how to set up Notakey Authentication Server.

== Screenshots ==

1. Two-factor authentication security policy settings.
2. Notakey authentication request options.
3. Notakey Authentication Server settings.
4. User authentication while waiting for response from Notakey app.

== Get Involved ==

Development happens [on GitHub](https://github.com/notakey/wordpress-two-factor/).

Here is how to get started:

    git clone https://github.com/notakey/wordpress-two-factor.git

Start development by starting a [devcontainer](https://code.visualstudio.com/docs/remote/containers).

Then open [a pull request](https://help.github.com/articles/creating-a-pull-request-from-a-fork/) with the suggested changes.

== Changelog ==

See the [release history](https://github.com/notakey/wordpress-two-factor/releases).
