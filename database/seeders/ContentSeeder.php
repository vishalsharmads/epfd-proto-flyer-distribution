<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ContentPage;
use Illuminate\Database\Seeder;

/** Default legal pages (Markdown bodies). Idempotent: only fills pages that do not exist yet. */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $brand = config('epfd.brand');
        $pages = [
            ['terms', 'Terms & Conditions', 1, <<<MD
These Terms & Conditions apply to every order placed with {$brand['name']} ("EPFD", "we", "us").

## 1. Orders and payment
- Prices are in Australian dollars and include GST unless stated otherwise. A tax invoice is issued for every payment.
- Distribution orders are booked once payment is received. Letterbox counts are **estimates** derived from the Geocoded National Address File (G-NAF) and may differ from actual letterboxes.
- Print orders enter production soon after acceptance. Changes may not be possible once printing has started.

## 2. Distribution
- Distributors will not deliver to letterboxes displaying "No junk mail" or "No advertising material" signs.
- Proof of distribution is provided as GPS coverage of each run. Coverage below 90% may be eligible for a credit.

## 3. Artwork
- You warrant that you hold the rights to print and distribute all uploaded artwork.
- Colours on screen may differ from printed colours. We are not responsible for errors in approved artwork.

## 4. Refunds and credits
Refunds and account credits are issued at our discretion in line with the Australian Consumer Law.

## 5. Contact
{$brand['address']} · {$brand['phone']} · {$brand['email']}
MD],
            ['community-guidelines', 'Community Guidelines', 2, <<<'MD'
Letterbox marketing reaches people at home. To keep it welcome, every campaign must follow these guidelines.

## Content we don't distribute
- Material that is misleading, offensive, discriminatory or unlawful.
- Political material without the authorisation statement required by law.
- Adult content, weapons, or anything targeting children inappropriately.

## Respecting residents
- We always respect "No junk mail" signs.
- Flyers must be placed fully inside letterboxes — never on cars, gates or doorsteps.

Breaches may lead to cancelled campaigns or account suspension.
MD],
            ['privacy', 'Privacy Policy', 3, <<<MD
We handle personal information in accordance with the Australian Privacy Principles (Privacy Act 1988).

## What we collect
- Business and contact details you provide when registering.
- Order, payment and invoice records. Card numbers are processed by our payment provider and never stored by us.
- GPS points recorded by distributors during runs, used only for proof of distribution.

## How we use it
To provide our services, issue invoices, support you and meet our legal obligations. We do not sell your information.

## Access and correction
Contact us at {$brand['email']} to access or correct your information, or to make a complaint.
MD],
        ];

        foreach ($pages as [$slug, $title, $sort, $body]) {
            ContentPage::firstOrCreate(['slug' => $slug], ['type' => 'legal', 'title' => $title, 'body' => $body, 'sort' => $sort, 'is_published' => true]);
        }
    }
}
