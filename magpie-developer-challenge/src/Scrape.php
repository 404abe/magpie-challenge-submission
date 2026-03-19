<?php
namespace App;

require 'vendor/autoload.php';

use Symfony\Component\DomCrawler\Crawler;

class Scrape
{
    private array $products = [];

    public function run(): void
    {
        $baseUrl = 'https://www.magpiehq.com/developer-challenge/smartphones';
        $page = 1;

        while (true) {
            $url = ($page === 1) ? $baseUrl : $baseUrl . '/?page=' . $page;
            $crawler = ScrapeHelper::fetchDocument($url);
            $productNodes = $crawler->filter('.product');

            if ($productNodes->count() === 0) {
                break;
            }

            $productNodes->each(function (Crawler $node) {
                $this->extractProduct($node);
            });

            $page++;
        }

        $this->saveResults();
    }

    private function extractProduct(Crawler $node): void
    {
        // prettier formatting for output
        $title = str_replace(' GB', 'GB', $node->filter('h3')->text());

        $rawPrice = $node->filter('.text-lg')->first()->text();
        $price = (float) filter_var($rawPrice, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

        $imageSrc = $node->filter('img')->attr('src');
        $imageUrl = 'https://www.magpiehq.com' . str_replace('..', '', $imageSrc);

        $capRaw = $node->filter('.product-capacity')->text();
        $capInt = (int) preg_replace('/[^0-9]/', '', $capRaw);
        
        $capacityMB = str_contains(strtoupper($capRaw), 'GB') ? $capInt * 1000 : $capInt;

        $rawAvailability = strtolower($node->text());
        if (str_contains($rawAvailability, 'out of stock')) {
            $availabilityText = 'Out of Stock';
            $isAvailable = false;
        } else {
            $availabilityText = 'In Stock';
            $isAvailable = true;
        }

        // shipping
        $shipNode = $node->filter('.text-sm')->last();
        $shippingText = $shipNode->count() > 0 ? trim($shipNode->text()) : '';
        $shippingDate = null;

        if (!empty($shippingText)) {
            if (preg_match('/\d{4}-\d{2}-\d{2}/', $shippingText, $match)) {
                $shippingDate = $match[0];
            } elseif (preg_match('/(\d+)(?:st|nd|rd|th)?\s+([A-Z][a-z]+)\s+(\d{4})/', $shippingText, $matches)) {
                $shippingDate = date('Y-m-d', strtotime($matches[1] . ' ' . $matches[2] . ' ' . $matches[3]));
            } elseif (str_contains(strtolower($shippingText), 'tomorrow')) {
                $shippingDate = date('Y-m-d', strtotime('tomorrow'));
            }
        }

        $node->filter('span[data-colour]')->each(function (Crawler $colorNode) use (
            $title, $price, $imageUrl, $capacityMB,
            $availabilityText, $isAvailable, $shippingText, $shippingDate
        ) {
            $colour = $colorNode->attr('data-colour');
            $this->products[$title . $capacityMB . $colour] = [
                'title'            => $title,
                'price'            => $price,
                'imageUrl'         => $imageUrl,
                'capacityMB'       => (int) $capacityMB,
                'colour'           => $colour,
                'availabilityText' => $availabilityText,
                'isAvailable'      => $isAvailable,
                'shippingText'     => $shippingText,
                'shippingDate'     => $shippingDate,
            ];
        });
    }

    private function saveResults(): void
    {
        $data = array_values($this->products);
        file_put_contents('output.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        echo 'Done! Found ' . count($this->products) . " unique products.\n";
    }
}

$scrape = new Scrape();
$scrape->run();