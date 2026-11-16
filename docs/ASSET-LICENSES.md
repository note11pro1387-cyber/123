# MR NEON — Asset licenses and provenance

## Vazirmatn webfont family

- **Typeface:** Vazirmatn, project version `33.003`; font internal name-table version inspected in all ten files.
- **Author/copyright:** The Vazirmatn Project Authors; author list identifies Saber Rastikerdar.
- **License:** SIL Open Font License 1.1. The complete upstream `OFL.txt` and `AUTHORS.txt` are preserved beside the font binaries at `assets/fonts/Vazirmatn/`.
- **Upstream project:** https://github.com/rastikerdar/vazirmatn
- **License source:** https://github.com/rastikerdar/vazirmatn/blob/master/OFL.txt
- **Restoration provenance:** the exact font bytes were recovered from the original project archive (`_old_original_zip2.zip`, whose internal top-level directory was renamed by the owner) and independently matched byte-for-byte against the historical `MR-NEON-CODE-SOURCE-COMPLETE.zip`. No font was downloaded, regenerated, subsetted, renamed, converted, or modified for this restoration.
- **Reason for restoration:** the compact W6-P11/W7 transfer intentionally omitted ten binary font assets while the active CSS and page preload references still pointed to them. W7-P14 restores those exact referenced files. The historical omission remains documented in `META/UPSTREAM-DISTRIBUTION-OMISSIONS.md`; it is no longer a current active-tree omission.

| File | Bytes | SHA-256 | Internal version | Weight class |
|---|---:|---|---|---:|
| `assets/fonts/Vazirmatn/webfonts/Vazirmatn-Black.woff2` | 50568 | `e65a05523e6c0a434265913805746ebe6ed48af843e6126a936d06f69d7d47ad` | Version 33.003 | 900 |
| `assets/fonts/Vazirmatn/webfonts/Vazirmatn-Bold.woff2` | 51020 | `836fae7d42d83faa249bc00e0099592be98a1fa260d22d82f269b6091e585627` | Version 33.003 | 700 |
| `assets/fonts/Vazirmatn/webfonts/Vazirmatn-ExtraBold.woff2` | 51120 | `cd67558bbca0ad319b89e3b2edb8a914f87f864951d7a9d24e1404cbf3b45b02` | Version 33.003 | 800 |
| `assets/fonts/Vazirmatn/webfonts/Vazirmatn-ExtraLight.woff2` | 51268 | `77efc8bd9708d9be6289738e67dd2142a36f8cb31cfa222ad55d844a15a9bbef` | Version 33.003 | 200 |
| `assets/fonts/Vazirmatn/webfonts/Vazirmatn-Light.woff2` | 51180 | `a3aa104f9a256734ca6769e017b4a2697c3036221e13758e0995a0cbeea969c4` | Version 33.003 | 300 |
| `assets/fonts/Vazirmatn/webfonts/Vazirmatn-Medium.woff2` | 51128 | `3333e31188a2b628db8780ca22fd5aad85bc083ccee9beb8d4d52db18cb98d48` | Version 33.003 | 500 |
| `assets/fonts/Vazirmatn/webfonts/Vazirmatn-Regular.woff2` | 50684 | `e382101336c6eb32cfb31381c027d02d2e0354bad08f6a395d4088beb3db3d91` | Version 33.003 | 400 |
| `assets/fonts/Vazirmatn/webfonts/Vazirmatn-SemiBold.woff2` | 51032 | `6a39a3c25eb18503cad590527b95bb5d4062b889a7ebbd3f01b0488d239e0499` | Version 33.003 | 600 |
| `assets/fonts/Vazirmatn/webfonts/Vazirmatn-Thin.woff2` | 50796 | `fa5ff0c39630b21e85f014d5c878d80cde8ab50cf3c022f117a20545f0dd6f56` | Version 33.003 | 100 |
| `assets/fonts/Vazirmatn/webfonts/Vazirmatn[wght].woff2` | 111152 | `4e3fa217d38fdafc1fea4414ceb58ca5e662cf0ab5fa735a8c8c20e8b42cad92` | Version 33.003 | 400 |

### Distribution notes

- The font software may be bundled with the website under the included OFL 1.1 terms. The font files must not be sold by themselves; the copyright notice and license must accompany each distributed copy.
- These files are restored unchanged. Do not re-encode, subset, rename, or replace them without a separately reviewed asset/build decision.
## Latin typography — W7-P41

- **Runtime choice:** the Latin UI uses the platform-native system sans-serif stack declared as `--font-latin` in `css/global.css`: `system-ui`, `-apple-system`, `BlinkMacSystemFont`, `Segoe UI`, then generic `sans-serif`.
- **Poppins evidence:** the exact W7-P40 active source contained no Poppins font binary or Poppins license bundle. The upstream Google Fonts Poppins directory publishes the family under SIL Open Font License 1.1 (`https://github.com/google/fonts/blob/main/ofl/poppins/OFL.txt`), but an upstream license declaration alone does not provide a verified local font binary to bundle.
- **Decision:** in accordance with the Master Handoff §16.2, use a system stack when a licensed, provenance-backed self-hosted font asset is not available in the project package. This avoids a runtime CDN dependency and avoids introducing an unverified or fabricated font file. Poppins can be reconsidered only with a pinned, provenance-backed font asset and its matching license supplied for a separate review.
- **Runtime dependencies:** no Google Fonts stylesheet, preconnect, `fonts.googleapis.com`, or `fonts.gstatic.com` request remains in the active page entry point or Report-Only CSP source policy. No Latin font binary is bundled by P41.
- The outgoing HTML verification email is outside the website font/CSP scope and is not changed here.
