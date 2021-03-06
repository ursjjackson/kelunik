<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\File;
use Amp\Promise;
use Kelunik\AcmeClient\Stores\CertificateStore;
use Kelunik\AcmeClient\Stores\KeyStore;
use Kelunik\AcmeClient\Stores\KeyStoreException;
use Kelunik\Certificate\Certificate;
use League\CLImate\Argument\Manager;
use League\CLImate\CLImate;
use function Amp\call;
use function Kelunik\AcmeClient\getArgumentDescription;

class Status {
    private $climate;

    public function __construct(CLImate $climate) {
        $this->climate = $climate;
    }

    public function execute(Manager $args): Promise {
        return call(function () use ($args) {
            $server = \Kelunik\AcmeClient\resolveServer($args->get('server'));
            $keyName = \Kelunik\AcmeClient\serverToKeyname($server);

            $storage = \Kelunik\AcmeClient\normalizePath($args->get('storage'));

            try {
                $keyStore = new KeyStore($storage);
                yield $keyStore->get("accounts/{$keyName}.pem");

                $setup = true;
            } catch (KeyStoreException $e) {
                $setup = false;
            }

            $this->climate->br();
            $this->climate->out('  [' . ($setup ? '<green> ✓ </green>' : '<red> ✗ </red>') . '] ' . ($setup ? 'Registered on ' : 'Not yet registered on ') . $server);
            $this->climate->br();

            if (yield File\exists($storage . "/certs/{$keyName}")) {
                $certificateStore = new CertificateStore($storage . "/certs/{$keyName}");

                /** @var array $domains */
                $domains = yield File\scandir($storage . "/certs/{$keyName}");

                foreach ($domains as $domain) {
                    $pem = yield $certificateStore->get($domain);
                    $cert = new Certificate($pem);

                    $symbol = \time() > $cert->getValidTo() ? '<red> ✗ </red>' : '<green> ✓ </green>';

                    if (\time() < $cert->getValidTo() && \time() + $args->get('ttl') * 24 * 60 * 60 > $cert->getValidTo()) {
                        $symbol = '<yellow> ⭮ </yellow>';
                    }

                    $this->climate->out('  [' . $symbol . '] ' . \implode(', ', $cert->getNames()));
                }

                $this->climate->br();
            }
        });
    }

    public static function getDefinition(): array {
        return [
            'server' => getArgumentDescription('server'),
            'storage' => getArgumentDescription('storage'),
            'ttl' => [
                'longPrefix' => 'ttl',
                'description' => 'Minimum valid time in days, shows ⭮ if renewal is required.',
                'defaultValue' => 30,
                'castTo' => 'int',
            ],
        ];
    }
}
