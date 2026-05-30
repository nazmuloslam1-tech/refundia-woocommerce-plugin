# Outstanding Integrations

This document tracks third-party integrations that are partially scaffolded in Refundia but require the merchant (Nazmul) to complete external setup before they can fully function.

## 1. Correos (Spanish Postal)

**Status:** Wallet UI built, label-purchase API connection NOT wired.

**What's needed:**
- Business contract with Correos (requires NIF/CIF Spanish tax ID)
- Account number from Correos
- API credentials (username + password OR API key)
- Where to apply: contact Correos business sales

Once credentials are obtained → ~1 hour to plug into `dashboard-src/api/labels/topup.js` (search for "// TODO: integrate Correos API").

---

## 2. Stripe

**Status:** Refund execution code-ready, OAuth Connect flow NOT built.

**What's needed:**
- Stripe business account
- Activate "Connect" platform mode in Stripe dashboard
- Get OAuth client_id + secret

Once obtained → ~1 day to build the one-click "Connect Stripe" flow on integrations page.

---

## 3. Resend (transactional email)

**Status:** API stubs ready, account setup NOT done.

**What's needed:**
- Resend account (free up to 3000/mo)
- Verify a sending domain (DNS records)
- Resend API key

Once obtained → ~2 hours to wire transactional emails (return approved, refund issued, etc.)

---

## 4. WhatsApp Business API

**Status:** Notification toggle UI ready, API call NOT wired.

**What's needed:**
- Meta Business account
- WhatsApp Business API registration (requires phone number verification)
- Pre-approved message templates (each template needs Meta approval)
- Phone Number ID + Access Token

Once obtained → ~3-4 hours to wire notifications per event.

---

## 5. WordPress.org Plugin Marketplace

**Status:** Submission package ready (refundia-v0.5.0.zip + assets).

**What's needed:**
- WordPress.org free account (login.wordpress.org/register)
- Manual submission via wordpress.org/plugins/developers/add/

Review takes 1-6 weeks. See WORDPRESS_SUBMISSION_GUIDE.md.
