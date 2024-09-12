#!/usr/bin/env php
<?php

require 'vendor/autoload.php';

use Google\Service\Sheets;
use Google\Service\Sheets\Sheet;
use GuzzleHttp\Client;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

// load .env vars
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$application = new Application();

// Connect to Google Sheets and Syncronize Data
$application->register('google-sync')
    ->setCode(function (InputInterface $input, OutputInterface $output): int {

        $client = new Google\Client();
        $client->setApplicationName("Data Sync");
        $client->setDeveloperKey($_ENV['GOOGLE_API_KEY']);

        $service = new Sheets($client);
        /** @var Sheets\Spreadsheet $spreadsheet */
        $spreadsheet = $service->spreadsheets->get($_ENV['GOOGLE_SHEET_ID']);

        echo $spreadsheet;

    });



// Download all files from the FTP, upload to Wordpress, then attach to products on WooCommerce
$application->register('ftp-sync')
    ->setCode(function (InputInterface $input, OutputInterface $output): int {
        // Download a list of files to iterate over
        $output->writeln('<info>FTP SYNC - CONNECTING TO FTP</info>');
        $output->writeln('<comment>Started '.date("m/d/Y H:i:s").'</comment>');
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

        $output->writeln('<comment>Finished '.date("m/d/Y H:i:s").'</comment>');
        return Command::SUCCESS;
    });

$application->run();