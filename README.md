# Jordan Quote API — PHP + Resend on Vercel

A tiny serverless PHP backend that receives quote/contact requests as JSON and
emails them to you via the [Resend](https://resend.com) API. No `mail()`, no
framework — just one PHP file and a `vercel.json`.

## Directory structure

```
jordan-quote-api/
├── api/
│   └── quote.php     # the serverless function → https://<project>.vercel.app/api/quote.php
├── vercel.json       # tells Vercel to run *.php with the community PHP runtime
├── .gitignore        # keeps .env / .vercel out of git
└── README.md
```

Anything inside `api/` becomes a serverless function. `vercel.json` maps
`api/*.php` to the `vercel-php` runtime (PHP 8.x).

## Environment variables (set in Vercel → Settings → Environment Variables)

| Variable         | Required | Example                                   | Purpose                                          |
|------------------|----------|-------------------------------------------|--------------------------------------------------|
| `EMAIL_API_KEY`  | ✅ yes   | `re_xxxxxxxx`                             | Your Resend API key (kept server-side only)      |
| `TO_EMAIL`       | rec.     | `troy@jordanpainting.com.au`              | Where quote requests are delivered               |
| `FROM_EMAIL`     | rec.     | `Jordan Painting <quotes@jordanpaint.au>` | Sender — must be a **Resend-verified** domain    |
| `ALLOWED_ORIGIN` | rec.     | `https://jordan-painting.vercel.app`      | Your frontend origin (safer than `*`)            |

> **Resend domain note:** to send to *real* customers you must verify a domain
> in Resend (add its DNS records). Until then, `FROM_EMAIL` defaults to
> `onboarding@resend.dev`, which can only deliver to your own Resend account
> email — perfect for a first test.

## Deploy — recommended (GitHub → Vercel, no CLI needed)

Your machine has no Node.js, and the **Vercel CLI needs Node**, so the easiest
path (and the same one your other sites use) is GitHub:

1. Create an empty GitHub repo and push this folder to it.
2. On [vercel.com](https://vercel.com): **Add New → Project → Import** the repo.
3. **Settings → Environment Variables:** add `EMAIL_API_KEY` (and `TO_EMAIL`,
   `FROM_EMAIL`, `ALLOWED_ORIGIN`).
4. **Deploy.** Your endpoint is `https://<project>.vercel.app/api/quote.php`.

Every future `git push` auto-deploys.

## Deploy — Vercel CLI (requires Node.js first)

```bash
# 0) Install Node.js (LTS) from https://nodejs.org — the CLI needs it
npm i -g vercel            # install the Vercel CLI

cd jordan-quote-api
vercel login               # one-time browser login
vercel                     # first deploy (creates the project) → preview URL

# add the secret env vars (repeat for TO_EMAIL / FROM_EMAIL / ALLOWED_ORIGIN)
vercel env add EMAIL_API_KEY production

vercel --prod              # promote to production
```

## Frontend call (vanilla JS `fetch`)

```js
async function sendQuote(form) {
  const payload = {
    name:    form.Name.value,
    email:   form.Email.value,
    details: form.Details.value,
    phone:   form.Phone.value,     // optional
    service: form.Service.value,   // optional
  };

  const res = await fetch('https://YOUR-API-PROJECT.vercel.app/api/quote.php', {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify(payload),
  });

  const data = await res.json();
  if (data.success) {
    // show your "Thanks — we'll be in touch" message
  } else {
    // show an error / fall back to the phone number
    console.error(data.error);
  }
}
```

## Quick test with curl

```bash
curl -X POST https://YOUR-API-PROJECT.vercel.app/api/quote.php \
  -H "Content-Type: application/json" \
  -d '{"name":"Jane","email":"jane@example.com","details":"Repaint a Queenslander in Manly."}'
# → {"success":true}
```
