<?php

/**
 * @file
 * Contains \Drupal\Console\Command\Config\ImportSingleCommand.
 */
namespace Drupal\Console\Command\Config;

use Drupal\config\StorageReplaceDataWrapper;
use Drupal\Console\Command\Shared\CommandTrait;
use Drupal\Console\Style\DrupalStyle;
use Drupal\Core\Config\CachedStorage;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\ConfigManager;
use Drupal\Core\Config\StorageComparer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Parser;

class ImportSingleCommand extends Command
{
    use CommandTrait;

    /** @var CachedStorage  */
    protected $configStorage;

    /** @var ConfigManager  */
    protected $configManager;

    /**
     * ImportSingleCommand constructor.
     * @param CachedStorage $configStorage
     * @param ConfigManager $configManager
     */
    public function __construct(
        CachedStorage $configStorage,
        ConfigManager $configManager
    ) {
        $this->configStorage = $configStorage;
        $this->configManager = $configManager;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('config:import:single')
            ->setDescription($this->trans('commands.config.import.single.description'))
            ->addArgument(
            'name', InputArgument::OPTIONAL,
            $this->trans('commands.config.import.single.arguments.name')
            )
            ->addArgument(
            'file', InputArgument::OPTIONAL,
            $this->trans('commands.config.import.single.arguments.file')
            )
            ->addOption(
                'config-names', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                $this->trans('commands.config.import.single.arguments.config-names')
            )
            ->addOption(
                'directory',
                '',
                InputOption::VALUE_OPTIONAL,
                $this->trans('commands.config.import.arguments.directory')
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new DrupalStyle($input, $output);

        $configNames = $input->getOption('config-names');
        $directory = $input->getOption('directory');

        $configNameArg = $input->getArgument('name');
        $fileName = $input->getArgument('file');

        $singleMode = FALSE;
        if (empty($configNames) && isset($configNameArg)) {
            $singleMode = TRUE;
            $configNames = array($configNameArg);
        }

        if (!isset($fileName) && !$directory) {
            $directory = config_get_config_directory(CONFIG_SYNC_DIRECTORY);
        }

        $ymlFile = new Parser();
        try {
            $source_storage = new StorageReplaceDataWrapper(
              $this->configStorage
            );

            foreach ($configNames as $configName) {
                // Allow for accidental .yml extension.
                if (substr($configName, -4) === '.yml') {
                    $configName = substr($configName, 0, -4);
                }

                if ($singleMode === FALSE) {
                    $fileName = $directory.DIRECTORY_SEPARATOR.$configName.'.yml';
                }

                $value = null;
                if (!empty($fileName) && file_exists($fileName)) {
                    $value = $ymlFile->parse(file_get_contents($fileName));
                }

                if (empty($value)) {
                    $io->error($this->trans('commands.config.import.single.messages.empty-value'));

                    return;
                }

                $source_storage->replaceData($configName, $value);
            }

            $storage_comparer = new StorageComparer(
              $source_storage,
              $this->configStorage,
              $this->configManager
            );

            if ($this->configImport($io, $storage_comparer)) {
                $io->success(
                  sprintf(
                    $this->trans(
                      'commands.config.import.single.messages.success'
                    ),
                    implode(", ", $configNames)
                  )
                );
            }
        } catch (\Exception $e) {
            $io->error($e->getMessage());

            return 1;
        }
    }

    private function configImport($io, StorageComparer $storage_comparer)
    {
        $config_importer = new ConfigImporter(
            $storage_comparer,
            \Drupal::service('event_dispatcher'),
            \Drupal::service('config.manager'),
            \Drupal::lock(),
            \Drupal::service('config.typed'),
            \Drupal::moduleHandler(),
            \Drupal::service('module_installer'),
            \Drupal::service('theme_handler'),
            \Drupal::service('string_translation')
        );

        if ($config_importer->alreadyImporting()) {
            $io->success($this->trans('commands.config.import.messages.already-imported'));
        } else {
            try {
                if ($config_importer->validate()) {
                    $sync_steps = $config_importer->initialize();

                    foreach ($sync_steps as $step) {
                        $context = array();
                        do {
                            $config_importer->doSyncStep($step, $context);
                        } while ($context['finished'] < 1);
                    }

                    return TRUE;
                }
            } catch (ConfigImporterException $e) {
                $message = 'The import failed due for the following reasons:' . "\n";
                $message .= implode("\n", $config_importer->getErrors());
                $io->error(
                    sprintf(
                        $this->trans('commands.site.import.local.messages.error-writing'),
                        $message
                    )
                );
            } catch (\Exception $e) {
                $io->error(
                    sprintf(
                        $this->trans('commands.site.import.local.messages.error-writing'),
                        $e->getMessage()
                    )
                );
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $io = new DrupalStyle($input, $output);
        $name = $input->getArgument('name');
        if (!$name) {
            $name = $io->ask(
                $this->trans('commands.config.import.single.questions.name')
            );
            $input->setArgument('name', $name);
        }

        $file = $input->getArgument('file');
        if (!$file) {
            $file = $io->ask(
                $this->trans('commands.config.import.single.questions.file')
            );
            $input->setArgument('file', $file);
        }
    }
}
