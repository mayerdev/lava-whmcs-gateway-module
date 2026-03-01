# WHMCS Lava.ru Payment Gateway

A payment gateway module for integrating the **Lava.ru** (Business API) payment system with the **WHMCS** billing panel.

![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)
![AI: Claude](https://img.shields.io/badge/AI-Claude-blueviolet)

## 🤖 About the Project

This module allows you to accept payments from clients via the Lava.ru service.
**Note:** The code for this project was entirely written using the **Claude** (Anthropic) AI.

## ✨ Features

*   Invoice creation via the Lava.ru Business API.
*   Automatic signature generation (HMAC SHA256).
*   Configurable invoice expiration time.
*   Request and response logging in the WHMCS "Module Log" for debugging.
*   Client redirect support after successful payment.

## 🚀 Installation

1.  Download the module file (e.g., `lava.php`).
2.  Upload the file to your WHMCS directory:
    `/modules/gateways/lava.php`
3.  *(Optional)* Make sure you have the callback handler file at `/modules/gateways/callback/lava.php` (it is required for automatic payment processing).

## ⚙️ Configuration

1.  Log in to the WHMCS admin panel.
2.  Go to **Settings** -> **Apps & Integrations**.
3.  Find and activate **Lava**.
4.  Fill in the configuration fields:
    *   **Shop ID**: Your project's UUID from the Lava.ru dashboard.
    *   **Secret Key**: The secret key for signing requests (from the project settings in Lava).
    *   **Webhook Key**: An additional key for verifying webhook notifications (if used).
    *   **Expire (minutes)**: Payment link lifetime (default: 60 minutes).
5.  Save changes.

## 📝 License

This project is distributed under the **MIT** License.

```text
MIT License

Copyright (c) 2026 apexnodes-developers

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

## ⚠️ Disclaimer

This code is provided "as is". Please test the module in a development environment (Dev mode) before using it in production. The author and the AI developer are not responsible for any financial losses or errors in the module's operation.
