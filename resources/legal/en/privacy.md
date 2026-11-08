# Privacy policy

_Last updated: {{last_updated}}_

{{company_legal_name}} ("ReviewPilot", "we") provides an online service that helps local businesses manage and answer their Google reviews. This policy explains which personal data we process, why, and what your rights are.

## 1. Who we are

{{company_block}}

Privacy questions: [{{privacy_email}}](mailto:{{privacy_email}}).

## 2. Two roles

- **Controller** for the data of our own customers and users (account details, billing, website use).
- **Processor** for the reviews and reviewer data we fetch from our customers' Google Business Profiles on their behalf. That processing is governed by our [data processing agreement](/legal/dpa).

## 3. What we process

| Data                                                                                                | Purpose                             | Legal basis                         | Retention                                                                  |
| --------------------------------------------------------------------------------------------------- | ----------------------------------- | ----------------------------------- | -------------------------------------------------------------------------- |
| Name, email, password (hashed), language                                                            | Account and login                   | Contract                            | While the account exists                                                   |
| Company name, invoice address, VAT number, payment data via Mollie                                  | Billing                             | Contract, legal obligation          | 7 years (tax law)                                                          |
| Google connection (encrypted tokens), locations, reviews, reviewer name as shown on Google, replies | Providing the service               | Contract (on the customer's behalf) | Until disconnected + {{disconnect_grace_days}} days, or earlier on request |
| Text sent to the AI model and its output                                                            | Drafting replies, analysing reviews | Contract                            | Full AI input/output {{ai_payload_days}} days, then metadata only          |
| Audit log (who approved what)                                                                       | Security and accountability         | Legitimate interest                 | While the organisation exists                                              |
| IP address, error reports                                                                           | Security, debugging                 | Legitimate interest                 | Up to 90 days                                                              |

We do **not** use advertising or tracking cookies. We only set functional cookies (login, security, language and appearance).

## 4. Google user data (Limited Use)

ReviewPilot requests access to the customer's Google Business Profile (`business.manage`) to read reviews and post replies. **ReviewPilot's use and transfer of information received from Google APIs adheres to the [Google API Services User Data Policy](https://developers.google.com/terms/api-services-user-data-policy), including the Limited Use requirements.** In practice:

- We use Google data only to provide the features the customer sees in ReviewPilot: showing, analysing, drafting and publishing replies to reviews.
- We do not sell this data, use it for advertising, or share it with third parties other than the sub-processors below that are needed to run the service.
- We do not use this data to train general AI models. Our AI provider does not train on data sent through its API.
- ReviewPilot staff only read this data with the customer's permission, for security purposes, or when required by law.
- You can revoke access at any time in ReviewPilot (Integrations → Disconnect) or at [myaccount.google.com/permissions](https://myaccount.google.com/permissions).

## 5. Sub-processors

{{subprocessors_table}}

Transfers outside the EEA rely on the EU-US Data Privacy Framework or the European Commission's standard contractual clauses.

## 6. Security

Encrypted connections (HTTPS), encrypted storage of Google tokens, data separated per organisation, role-based permissions, two-factor authentication, a full audit log and daily backups.

## 7. Your rights

You have the right to access, rectify, erase, restrict, port and object. Admins can export all their organisation's data themselves (Organisation → Export data) and delete the organisation. Send other requests to [{{privacy_email}}](mailto:{{privacy_email}}); we reply within one month.

If you wrote a review and want it removed: the review lives on Google, so contact the business or Google. We help the business with that.

You can also lodge a complaint with the Dutch Data Protection Authority ([Autoriteit Persoonsgegevens](https://autoriteitpersoonsgegevens.nl)).

## 8. Changes

We may update this policy. We inform customers by email of material changes.
