# Google verification checklist

ReviewPilot needs two separate approvals from Google:

1. **Business Profile API access** (quota). Already requested (case 2-0667000041629). Until it is approved, the quota is 0 and imports fail with "not approved yet".
2. **OAuth app verification** of the consent screen. `business.manage` is a sensitive scope, so Google reviews the app before anyone outside the "test users" list can connect. Without this step, customers see an "unverified app" warning and are capped at 100 users.

Do step 2 once the API access is approved and the site is live with the pages below.

## 1. What must be online first

| Item | URL / file | Status in the code |
|---|---|---|
| Homepage that explains the app | https://reviewspilot.nl | Done (`resources/js/pages/Welcome.vue`) |
| Privacy policy incl. Limited Use statement | https://reviewspilot.nl/legal/privacy (short: `/privacy`) | Done; fill in the `COMPANY_*` values in `.env` |
| Terms of service | https://reviewspilot.nl/legal/terms | Done |
| App logo, 120×120 px | https://reviewspilot.nl/logo-120.png | Done (`public/logo-120.png`); a 512 px version is at `/logo-512.png` |
| Support email | `COMPANY_EMAIL` (e.g. info@reviewspilot.nl) | Create the mailbox in Plesk |

The privacy policy must name the same company and contact details as the consent screen. Fill these in `.env` and run `php artisan optimize`:

```
COMPANY_LEGAL_NAME=...
COMPANY_ADDRESS=...
COMPANY_POSTAL_CODE=...
COMPANY_CITY=...
COMPANY_KVK=...
COMPANY_VAT_NUMBER=...
COMPANY_EMAIL=info@reviewspilot.nl
COMPANY_PRIVACY_EMAIL=privacy@reviewspilot.nl
HOSTING_PROVIDER=...   # who hosts the server, listed as a sub-processor
```

## 2. Verify the domain (Google Search Console)

1. Go to https://search.google.com/search-console and sign in with the **same Google account that owns the Cloud project**.
2. Add a property → **Domain** → `reviewspilot.nl`.
3. Google shows a TXT record (`google-site-verification=...`). In Plesk: *Websites & Domains → DNS Settings → Add Record* → type **TXT**, host empty (`reviewspilot.nl.`), value = the code.
4. Wait a few minutes, then click **Verify**.

## 3. Branding (Google Cloud Console → Google Auth Platform → Branding)

- App name: **ReviewPilot** (must match the name on the homepage)
- User support email: your support address
- App logo: upload `public/logo-120.png`
- Application home page: `https://reviewspilot.nl`
- Privacy policy: `https://reviewspilot.nl/legal/privacy`
- Terms of service: `https://reviewspilot.nl/legal/terms`
- Authorised domains: `reviewspilot.nl`
- Developer contact: your email

## 4. Data access (scopes)

Only one scope: `https://www.googleapis.com/auth/business.manage`, plus `openid` and `email` (non-sensitive).

**Scope justification** (paste and adjust):

> ReviewPilot is a review management tool for restaurants and other hospitality businesses. Business owners connect their own Google Business Profile so ReviewPilot can (1) list the locations they manage, (2) read the reviews of those locations, and (3) post or update the owner's reply to a review after the owner approved it, or automatically within rules the owner configured. These actions are only available through the business.manage scope; there is no narrower scope for reading reviews and replying. We do not use the data for advertising, do not sell it, and do not use it to train AI models. Owners can disconnect at any time inside the app.

## 5. Demo video (unlisted YouTube link)

Google asks for a short screen recording (2–4 minutes) in **English**. The video must show the browser address bar so the OAuth client ID is visible on the consent screen. Script:

1. Open https://reviewspilot.nl and show the homepage and the privacy policy link (5 s).
2. Log in. Switch the language to EN (user menu → Language) so the reviewer can follow.
3. Go to **Integrations → Connect Google**.
4. On Google's consent screen, **pause on the page** so the app name and the requested permission (Business Profile) are visible. The URL must contain the `client_id`. Click *Allow*.
5. Back in ReviewPilot: show the list of discovered locations and click **Import** for one location.
6. Open **Reviews**, open one review, and show the three suggested replies.
7. Edit a reply, click **Approve & publish**, then open the location on Google Maps and show the reply that appeared.
8. Show **Integrations → Disconnect**, explaining that this revokes access, and show the privacy policy section "Google user data (Limited Use)".

Use a real location with at least one review (the friend's "Reza Perzische Tapijten" profile). Remove the test reply afterwards if needed.

## 6. Submit

*Google Auth Platform → Verification centre → Prepare for verification*. Answer the questions, add the video link and the justification above. The review usually takes days to a few weeks. Google emails questions to the developer contact address; answer them quickly.

## 7. Until verification is done

Keep the consent screen in **Testing** mode and add each pilot customer's Google account under *Audience → Test users* (max. 100). Test users see a warning screen they can click through.
