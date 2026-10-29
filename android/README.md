# FireFunds for Android

A small companion app: it reads the notifications of the apps you choose
(your bank's, Satispay…) and sends them to your FireFunds server, where they
become transactions to review ("To review"). No open banking, no data kept
on the phone beyond what waits to be sent.

Install it from your FireFunds server: Preferences → API → Android app. The
same page says how to set it up (a "Drafts only" API token, the server's
address, access to notifications, which apps to read).

## Building

No Android SDK is needed on your machine, only Docker:

```bash
android/build.sh
```

It builds the release APK in a container (`ghcr.io/cirruslabs/android-sdk`)
and copies it to `frontend/public/downloads/firefunds.apk`, which the web
app offers for download.

The APK is signed with the key named in `android/signing.env`. That file
and the keystore are not part of this repository: to build your own, make a
key with `keytool` and write `signing.env` with `STORE_FILE`,
`STORE_PASSWORD`, `KEY_ALIAS` and `KEY_PASSWORD`. Without it the APK is
left unsigned. An app signed with another key can't be installed over this
one.
