<?php

namespace Aws\Symfony;

use Aws\Sdk;
use Composer\Script\Event;
use Symfony\Component\DependencyInjection\Container;

class DocumentationUpdater
{
    const SERVICES_TABLE_START = '<!-- BEGIN SERVICE TABLE -->';
    const SERVICES_TABLE_END = '<!-- END SERVICE TABLE -->';
    const SERVICE_CLASS_DELIMITER = '@CLASS@';
    const DOCS_URL_TEMPLATE = 'http://docs.aws.amazon.com/aws-sdk-php/v3/api/class-@CLASS@.html';

    protected $projectRoot;

    public static function updateFromComposer(Event $e)
    {
        $root = dirname($e->getComposer()->getConfig()->get('vendor-dir'));

        require implode(DIRECTORY_SEPARATOR, [$root, 'vendor', 'autoload.php']);

        (new static($root))
            ->updateReadMe()
            ->updateComposerJSON();
    }

    public function __construct($projectRoot = '..')
    {
        $this->projectRoot = $projectRoot;
    }

    /**
     * @return self
     */
    public function updateReadMe()
    {
        $readMeParts = $this->getReadMeWithoutServicesTable();
        $servicesTable = self::SERVICES_TABLE_START . "\n"
            . $this->getServicesTable()
            . self::SERVICES_TABLE_END;

        file_put_contents(
            $this->getReadMePath(),
            $this->replaceSdkVersionNumber(
                implode($servicesTable, $readMeParts)
            )
        );

        return $this;
    }

    /**
     * @return self
     */
    public function updateComposerJSON()
    {
        $packageTags = ['aws', 'amazon', 'symfony', 'symfony2'];
        $jsonPath = $this->projectRoot . DIRECTORY_SEPARATOR . 'composer.json';
        $composerJson = json_decode(file_get_contents($jsonPath));

        $composerJson->keywords = array_merge(
            $packageTags,
            array_map(function ($serviceId) {
                return preg_replace('/aws[\._]/', '', $serviceId);
            }, $this->getAWSServices($this->getContainer()))
        );

        file_put_contents($jsonPath, json_encode($composerJson, JSON_PRETTY_PRINT));

        return $this;
    }


    private function getReadMeWithoutServicesTable()
    {
        $readMe = file_get_contents($this->getReadMePath());
        $tablePattern = '/' . preg_quote(self::SERVICES_TABLE_START)
            . '.*' . preg_quote(self::SERVICES_TABLE_END) . '/s';

        return preg_split($tablePattern, $readMe, 2);
    }

    private function getReadMePath()
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . 'README.md';
    }

    private function getServicesTable()
    {
        $table = "Service | Instance Of\n--- | ---\n";

        $container = $this->getContainer();
        foreach ($this->getAWSServices($container) as $service) {
            $serviceClass = get_class($container->get($service));
            $apiDocLink = preg_replace(
                '/' . preg_quote(self::SERVICE_CLASS_DELIMITER) . '/',
                str_replace('\\', '.', $serviceClass),
                self::DOCS_URL_TEMPLATE
            );

            $table .= "$service | [$serviceClass]($apiDocLink) \n";
        }

        return $table;
    }

    private function getAWSServices(Container $container)
    {
        return array_filter($container->getServiceIds(), function ($service) {
            return strpos($service, 'aws') === 0;
        });
    }

    private function replaceSdkVersionNumber($readMeText)
    {
        $start = '<span class="sdk-version">';
        $version = Sdk::VERSION;
        $end = '</span>';
        $pattern = '/' . preg_quote($start, '/') . '[^<]*'
            . preg_quote($end, '/') . '/';

        return preg_replace($pattern, "$start{$version}$end", $readMeText);
    }

    /**
     * @return Container
     */
    private function getContainer()
    {
        static $container = null;

        if (empty($container)) {
            $kernel = new \AppKernel('test', true);
            $kernel->boot();
            $container = $kernel->getContainer();
        }

        return $container;
    }
}