# ERP Mobile

Android app for warehouse staff: create **draft Sales Orders** by scanning the QR labels on
fabric rolls, and create **draft Delivery Orders** from confirmed SOs. Office staff confirm and
continue SO → DO → Invoice in the web ERP system.

Built with [Expo](https://expo.dev) (React Native). Completely self-contained — it only talks to
the existing ERP backend API (`/api/login`, `/api/v1/...`) and never touches backend or frontend code.

## What the app does

| Screen | What it does |
| --- | --- |
| Settings | Set the ERP server URL (e.g. `http://192.168.1.10`). Tested & saved on the device. |
| Login | Email + password → Sanctum Bearer token (`POST /api/login`). |
| New Sales Order | Pick customer → scan roll QR labels with the camera → items group by product + colour → **Save as Draft**. |
| Sales Orders | Paginated list with search + status filter, and detail view. |
| New Delivery Order | Pick a confirmed SO → tick its allocated rolls (tap or scan-to-tick) / enter quantities → **Save as Draft**. |
| Delivery Orders | Paginated list + detail view. |

The app only ever saves drafts. Confirming (which posts stock movements) happens in the web system.

The signed-in user needs these Spatie permissions: `create_sales_orders`, `view_sales_orders`,
`create_delivery_orders`, `view_delivery_orders`.

## Run it in development

1. Install dependencies (first time only):

   ```bash
   cd mobile
   npm install
   ```

2. Make sure the backend is reachable from the phone:
   - Phone and PC on the **same Wi-Fi**.
   - Use the PC's LAN IP (run `ipconfig` → IPv4 Address), **not** `localhost`.
   - Apache/Laragon must be running and allowed through Windows Firewall.

3. Start the dev server:

   ```bash
   npx expo start
   ```

4. Install **Expo Go** from the Play Store on the phone and scan the QR code shown in the
   terminal. (Or press `a` to open an Android emulator if Android Studio is installed.)

5. In the app: Settings → enter `http://<PC-IP>` → Test & Save → log in with an ERP user.

## Build the installable APK

Uses Expo's free cloud build service (EAS). One-time setup: create a free account at
[expo.dev](https://expo.dev), then:

```bash
npm install -g eas-cli
eas login
cd mobile
eas build --platform android --profile preview
```

When the build finishes, EAS prints a link — open it on the phone (or scan the QR) to download
and install the `.apk`. Android will ask to allow installs from unknown sources.

Notes:
- `usesCleartextTraffic` is enabled so the APK can call plain `http://` LAN servers.
  If the backend moves to HTTPS with a domain, remove that plugin entry from `app.json`.
- The server URL is set inside the app (Settings), so the same APK works against any deployment.

## Project layout

```
src/
  api/         axios client + endpoint wrappers (mirrors frontend/src/api)
  store/       session context: server URL, Sanctum token (SecureStore), user + permissions
  components/  Scanner (expo-camera QR), DocList (paginated list), ui primitives
  app/         expo-router screens: login, settings, home, so/*, do/*
```
