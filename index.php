#!/usr/bin/env php
<?php

require 'vendor/autoload.php';

use Dotenv\Parser\Value;
use Google\Service\Sheets;
use GuzzleHttp\Client;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use Kolstad\ProductToImport;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

// load .env vars
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$application = new Application();

$application->register('delete-categories')
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $output->writeln('<info>DELETING CATEGORIES</info>');
        $output->writeln('<comment>Started ' . date("m/d/Y H:i:s") . '</comment>');
        $wordpressClient = new Client(['base_uri' => $_ENV['WORDPRESS_URL']]);

        $contents = [];
        for ($page = 1; $page <= 1000; $page++) {
            $response = $wordpressClient->get('/wp-json/wc/v3/products/categories', [
                'query' => ['per_page' => 100, 'page' => $page],
                'auth' => [$_ENV['WORDPRESS_USER'], $_ENV['WORDPRESS_PASS']],
            ]);
            if ($response->getStatusCode() == 200) {
                $array = json_decode($response->getBody(), true);
                if (count($array) > 0) {
                    $contents = array_merge($contents, $array);
                } else {
                    $page = 100000;
                }
            } else {
                $page = 100000;
            }
        }
        $progressBar = new ProgressBar($output, count($contents));
        $progressBar->start();
        foreach ($contents as $content) {
            $wordpressClient->delete('/wp-json/wc/v3/products/categories/' . $content['id'], [
                'query' => ['force' => true],
                'auth' => [$_ENV['WORDPRESS_USER'], $_ENV['WORDPRESS_PASS']],
            ]);
            $progressBar->advance();
        }
        $progressBar->finish();
    });


// Connect to Google Sheets and Sync categories with WooCommerce
$application->register('sync-categories')
    ->setCode(function (InputInterface $input, OutputInterface $output): int {
        $client = new Google\Client();
        $client->setApplicationName("Data Sync");
        $client->addScope(Google\Service\Sheets::SPREADSHEETS);
        $client->setDeveloperKey($_ENV['GOOGLE_API_KEY']);
        $service = new Sheets($client);
        $values = $service->spreadsheets_values->get($_ENV['GOOGLE_SHEET_ID'], 'KOLSTAD!E2:E3000');

        // @todo switch to OAUTH
        // @todo create categories if they do not exist and get the IDs
        // @todo same for tags

        $wordpressClient = new Client(['base_uri' => $_ENV['WORDPRESS_URL']]);

        $wooCategories = [];

        /**
         * Build existing categories
         */
        $page = 1;
        while (is_int($page)) {
            $response = $wordpressClient->get('/wp-json/wc/v3/products/categories', [
                'query' => ['page' => $page, 'per_page' => 100],
                'auth' => [$_ENV['WORDPRESS_USER'], $_ENV['WORDPRESS_PASS']],
            ]);
            $header = $response->getHeaders();
            $response = json_decode($response->getBody(), true);

            if ($page <= $header['x-wp-totalpages'][0]) {
                $page++;
            } else {
                $page = null;
            }

            foreach ($response as $category) {
                $wooCategories[$category['name']] = $category['id'];
            }
        }


        foreach ($values->getValues() as $value) {
            $catArr = explode('>', $value[0]);
            $parent = trim(array_shift($catArr));

            if (!isset($wooCategories[str_replace('&','&amp;',$parent)])) {
                $output->writeln('<info>' . $parent . '</info>');

                $response = $wordpressClient->post('/wp-json/wc/v3/products/categories', [
                    'json' => ['name' => $parent],
                    'auth' => [$_ENV['WORDPRESS_USER'], $_ENV['WORDPRESS_PASS']],
                ]);
                $wooCategories[str_replace('&','&amp;',$parent)] = json_decode($response->getBody()->getContents())->id;
            }
            foreach ($catArr as $child) {
                $child = trim(str_replace('>', '', $child));
                if ($child != "") {
                    $category = [
                        'name' => $child,
                        'parent' => $wooCategories[str_replace('&','&amp;',$parent)],
                    ];

                    if (!isset($wooCategories[str_replace('&','&amp;',$child)])) {
                        $output->writeln('<info>' . $child . '</info>');
                        $response = $wordpressClient->post('/wp-json/wc/v3/products/categories', [
                            'json' => $category,
                            'auth' => [$_ENV['WORDPRESS_USER'], $_ENV['WORDPRESS_PASS']],
                        ]);
                        $wooCategories[str_replace('&','&amp;',$child)] = json_decode($response->getBody()->getContents())->id;
                    }
                    // child is now the parent of future children
                    $parent = $child;
                }
            }
        }

        echo '1234';
    });

/**
 * Assign categories from Google sheet to SKU
 */
$application->register('category-sync')
    ->setCode(function (InputInterface $input, OutputInterface $output): int {
        $client = new Google\Client();
        $client->setApplicationName("Data Sync");
        $client->addScope(Google\Service\Sheets::SPREADSHEETS);
        $client->setDeveloperKey($_ENV['GOOGLE_API_KEY']);
        $service = new Sheets($client);
        $values = $service->spreadsheets_values->get($_ENV['GOOGLE_SHEET_ID'], 'KOLSTAD!A2:X3000');


        // Configure woocommerce API
        $woocommerce = new Automattic\WooCommerce\Client(
            url: $_ENV['WORDPRESS_URL'],
            consumerKey: $_ENV['WORDPRESS_KEY'],
            consumerSecret: $_ENV['WORDPRESS_SEC']
        );

        // Upload if exists and delete from FTP
        $wordpressClient = new Client(['base_uri' => $_ENV['WORDPRESS_URL']]);

        /**
         * Build existing categories
         */
        $page = 1;
        $wooCategories = [];
        while (is_int($page)) {
            $response = $wordpressClient->get('/wp-json/wc/v3/products/categories', [
                'query' => ['page' => $page, 'per_page' => 100],
                'auth' => [$_ENV['WORDPRESS_USER'], $_ENV['WORDPRESS_PASS']],
            ]);
            $header = $response->getHeaders();
            $response = json_decode($response->getBody(), true);

            if ($page <= $header['x-wp-totalpages'][0]) {
                $page++;
            } else {
                $page = null;
            }

            foreach ($response as $category) {
                $wooCategories[$category['name']] = $category['id'];
            }
        }



        $i = 2;
        foreach ($values->getValues() as $key => $value) {
            $productToImport = new ProductToImport($value);

            $product = (array)$woocommerce->get('products/' . $productToImport->wooId);

            /**
             * remove uncategorized if it exists
             */
            foreach ($product['categories'] as $key => $category) {
                if ($category->slug == 'uncategorized') {
                    unset($product['categories'][$key]);
                }
            }

            /**
             * Add product category
             */
            $product['categories'][] = ['id' => $wooCategories[str_replace('&','&amp;',$productToImport->lastCategory)]];

            $response = $woocommerce->put('products/' . $productToImport->wooId, $product);

            $output->writeln('<info>' . $response->sku . '</info>');
        }

        return 0;
    });

// Connect to Google Sheets and Syncronize Data
$application->register('google-sync')
    ->setCode(function (InputInterface $input, OutputInterface $output): int {
        $client = new Google\Client();
        $client->setApplicationName("Data Sync");
        $client->addScope(Google\Service\Sheets::SPREADSHEETS);
        $client->setDeveloperKey($_ENV['GOOGLE_API_KEY']);
        $service = new Sheets($client);
        $values = $service->spreadsheets_values->get($_ENV['GOOGLE_SHEET_ID'], 'KOLSTAD!A2:X3000');

        // Configure woocommerce API
        $woocommerce = new Automattic\WooCommerce\Client(
            url: $_ENV['WORDPRESS_URL'],
            consumerKey: $_ENV['WORDPRESS_KEY'],
            consumerSecret: $_ENV['WORDPRESS_SEC']
        );

        // @todo switch to OAUTH
        // @todo create categories if they do not exist and get the IDs
        // @todo same for tags

        // Upload if exists and delete from FTP
        $wordpressClient = new Client(['base_uri' => $_ENV['WORDPRESS_URL']]);

        $i = 2;
        foreach ($values->getValues() as $key => $value) {
            $productToImport = new ProductToImport($value);

            // should we import?
            if ($productToImport->import()) {
                // have woocomemerce id?
                if ($productToImport->wooId && $productToImport->update()) {
                    $product = (array)$woocommerce->get('products/' . $productToImport->wooId);
                    $response = $woocommerce->put(
                        'products/' . $productToImport->wooId,
                        $productToImport->getWooObject($product)
                    );
                } else {
                    $response = $woocommerce->post('products', $productToImport->getWooObject());
                }
                $productToImport->wooId = $response->id;
                $wooId = new Sheets\ValueRange(['values' => [$productToImport->wooId]]);
                $updateNo = new Sheets\ValueRange(['values' => ['NO']]);
                $modifiedDate = new Sheets\ValueRange(['values' => [$response->date_modified]]);
                $slug = new Sheets\ValueRange(['values' => [$response->permalink]]);
                $service->spreadsheets_values->update($_ENV['GOOGLE_SHEET_ID'], 'KOLSTAD!C' . $key + $i, $wooId);
                $service->spreadsheets_values->update($_ENV['GOOGLE_SHEET_ID'], 'KOLSTAD!D' . $key + $i, $updateNo);
                $service->spreadsheets_values->update($_ENV['GOOGLE_SHEET_ID'], 'KOLSTAD!W' . $key + $i, $modifiedDate);
                $service->spreadsheets_values->update($_ENV['GOOGLE_SHEET_ID'], 'KOLSTAD!X' . $key + $i, $slug);
            }
        }
    });


// Download all files from the FTP, upload to Wordpress, then attach to products on WooCommerce
$application->register('ftp-sync')
    ->setCode(function (InputInterface $input, OutputInterface $output): int {
        // Download a list of files to iterate over
        $output->writeln('<info>FTP SYNC - CONNECTING TO FTP</info>');
        $output->writeln('<comment>Started ' . date("m/d/Y H:i:s") . '</comment>');
        $filesystem = new Filesystem(
            new SftpAdapter(
                new SftpConnectionProvider(
                    host: $_ENV['FTP_HOST'],
                    username: $_ENV['FTP_USER'],
                    password: $_ENV['FTP_PASS'],
                    port: $_ENV['FTP_PORT']
                ),
                $_ENV['FTP_PATH'],
            )
        );


        // creates a new progress bar (total file count)
        $contents = $filesystem->listContents($_ENV['FTP_PATH'])->toArray();
        $progressBar = new ProgressBar($output, count($contents));
        $progressBar->start();

        /** @var FileAttributes $file */
        foreach ($contents as $file) {
            if ($file->type() == 'file') {
                $sku = explode('-', $file->path())[0];

                $sku = str_replace(search: '_', replace: '/', subject: $sku);

                $woocommerce = new Automattic\WooCommerce\Client(
                    url: $_ENV['WORDPRESS_URL'],
                    consumerKey: $_ENV['WORDPRESS_KEY'],
                    consumerSecret: $_ENV['WORDPRESS_SEC']
                );

                // Search WooCommerce for Product SKU
                $products = $woocommerce->get('products', ['sku' => $sku]);

                if (count($products) == 1) {
                    $product = $products[0];

                    try {
                        // Upload if exists and delete from FTP
                        $resource = $filesystem->readStream($file->path());
                        $wordpressClient = new Client(['base_uri' => $_ENV['WORDPRESS_URL']]);
                        $response = $wordpressClient->post('/wp-json/wp/v2/media', [
                            'headers' => [
                                'Content-Disposition' => 'attachment; filename="' . $file->path() . '"',
                                'Content-Type' => mime_content_type($resource),
                            ],
                            'auth' => [$_ENV['WORDPRESS_USER'], $_ENV['WORDPRESS_PASS']],
                            'body' => $resource
                        ]);

                        $contents = json_decode($response->getBody()->getContents());

                        // Call Update on WooCommerce
                        $images = $product->images;
                        $images[] = ['src' => $contents->source_url];
                        $response = $woocommerce->put('products/' . $product->id, ['images' => $images]);

                        $filesystem->delete($file->path());
                    } catch (Exception $e) {
                        $output->writeln('<error>' . $e->getMessage() . '</error>');
                        $filesystem->move($file->path(), 'error/' . $file->path());
                    }
                } elseif (count($products) > 1) {
                    $output->writeln(
                        '<comment>More than one product match found. Skipping product ' . $sku . '</comment>'
                    );
                } else {
                    $filesystem->move($file->path(), 'notfound/' . $file->path());
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();

        $output->writeln('<comment>Finished ' . date("m/d/Y H:i:s") . '</comment>');
        return Command::SUCCESS;
    });


// Download all files from the FTP, upload to Wordpress, then attach to products on WooCommerce
$application->register('karmak-sync')
    ->setCode(function (InputInterface $input, OutputInterface $output): int {
        // Download a list of files to iterate over
        $output->writeln('<info>SYNC WITH KARMAK API</info>');
        $output->writeln('<comment>Started ' . date("m/d/Y H:i:s") . '</comment>');


        $output->writeln('<comment>Finished ' . date("m/d/Y H:i:s") . '</comment>');
        return Command::SUCCESS;
    });

$application->run();