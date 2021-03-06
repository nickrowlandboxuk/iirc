<?php

namespace BoxUK\Bundle\IrcLogsAppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;


/**
 * Indexes logs into Solr.
 *
 */
class IndexLogsCommand extends ContainerAwareCommand
{

    /**
     * @var array
     */
    private $channelLineNumbers = array();

    /**
     * @var \BoxUK\Bundle\IrcLogsAppBundle\Solr\SolrRepository
     */
    private $solr;


    /**
     * @var \Solarium_Client
     */
    private $solarium;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('iirc:index')
            ->setDefinition(array(
            new InputOption('force', null, InputOption::VALUE_NONE, 'Force a re-indexing of all packages'),
        ))
            ->setDescription('Indexes logs in Solr');
    }

    /**
     *
     * @param InputInterface  $input  An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->solr = $this->getContainer()->get('box_uk_irc_logs_app.solr_repository');
        $this->solarium = $this->solr->getSolarium();
    }
    /**
     *
     * @param InputInterface  $input  An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance\
     *
     * @return array
     */
    protected function getLogFiles(InputInterface $input, OutputInterface $output) {

        $logFileDir = $this->getContainer()->getParameter('irc_log_file_directory');

        $output->writeln('<info>Indexing directory: </info>' . $logFileDir . '');

        $finder = new Finder();
        $finder->in($logFileDir)->files()->name('*.log');

        $sort = function (\SplFileInfo $a, \SplFileInfo $b)
        {

            $regex = '/^#\w+(?<date>\[(?<year>\d{4})\.(?<month>\d{2})\.(?<day>\d{2})\])?.log$/';
            preg_match($regex, $a->getFilename(), $aMatch);
            preg_match($regex, $b->getFilename(), $bMatch);

            $aDate = date_create()->setTime(0,0,0);
            if (array_key_exists('date', $aMatch)) {
                $aDate->setDate($aMatch['year'], $aMatch['month'], $aMatch['day']);
            }
            $bDate = date_create()->setTime(0,0,0);
            if (array_key_exists('date', $bMatch)) {
                $bDate->setDate($bMatch['year'], $bMatch['month'], $bMatch['day']);
            }

            if ($aDate == $bDate) {
                return 0;
            }

            return $aDate < $bDate ? 1 : -1;
        };

        $finder->sort($sort);

        return iterator_to_array($finder->getIterator());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $force = $input->getOption('force');


        if ($force) {
            $output->writeln('<info>Deleting existing index</info>');

            $update = $this->solarium->createUpdate();
            $update->addDeleteQuery('*:*');
            $update->addCommit();

            $this->solarium->update($update);
        }

        $buffer = $this->solarium->getPlugin('bufferedadd');
        /** @var $buffer \Solarium_Plugin_BufferedAdd */
        $buffer->setBufferSize(100);
        $helper = new \Solarium_Query_Helper();

        $documents = array();

        foreach ($this->getLogFiles($input, $output) as $file) {

            if (false === $reader = new BackwardsFileReader($file)) {
                $output->writeln('<error>Failed to open file: ' . $file . '.</error>');
                continue;
            }

            $output->writeln('  <info>Indexing : </info>' . $file . '');

            $lastIndexedDate = $this->solr->getLastIndexedDate();

            try {
                while ($line = $reader->readLine()) {

                    preg_match(self::getLogFormatRegex(), $line, $matches);

                    if (!$matches) {
                        //$output->writeln('<error>Regex failed for line: ' . $line . '.</error>');
                        continue;
                    }

                    $document = $this->updateDocumentFromFile($matches);

                    if ($lastIndexedDate && $lastIndexedDate >= $document['datetime']) {

                        // don't go beyond our last known indexed datetime
                        if ($lastIndexedDate > $document['datetime']) {
                            break;
                        }

                        // because messages can appear on the same date time (maximum log precision = seconds)
                        // we have to check we haven't seen this specific line before, to prevent indexing
                        // duplicate rows
                        if ($lastIndexedDate == $document['datetime'] && $this->lineAlreadyIndexed($document['id'])) {
                            break;
                        }

                    }

                    $documents[] = $document;

                }

            } catch (\Exception $e) {
                $output->writeln('<error>Failed to read file. Exception: ' . $e->getMessage() . '</error>');
            }
        }

        // reverse to line numbers are correct
        foreach (array_reverse($documents) as $document) {

            $document['lineNumber'] = $this->lineNumberFor($document['channel'], $document['datetime']);
            $document['datetime'] = $helper->formatDate($document['datetime']);

            try {
                $buffer->createDocument($document);
            } catch (\Exception $e) {
                $output->writeln('<error>Exception: ' . $e->getMessage() . '.</error>');
            }
        }


        $buffer->commit();

        $output->writeln('<info>Optimizing the index</info>');

        // Optimize
        $update = $this->solarium->createUpdate();
        $update->addOptimize(true, false, 5);
        $this->solarium->update($update);

        $output->writeln('<info>Done</info>');
    }

    /**
     * Does an ID already exist in the index
     *
     * @param $id
     * @return bool
     */
    private function lineAlreadyIndexed($id) {
        $query = $this->solarium->createSelect();

        $query->setRows(0);

        $query
            ->createFilterQuery('channel')
            ->setQuery('id:' . $id)
        ;

        return $this->solarium->select($query)->getNumFound() > 0;

    }

    /**
     * @param array $matches
     * @throws \RuntimeException
     * @internal param string $line
     * @return array
     */
    private function updateDocumentFromFile(array $matches)
    {

        $date = $matches['date'];
        $datetime = \DateTime::createFromFormat('D M d G:i:s Y', $date, new \DateTimeZone('UTC'));

        if (!$datetime) {
            throw new \RuntimeException('Could not parse date: ' . $date);
        }

        $channel = $matches['channel'];
        $nick = $matches['nick'];
        $username = $matches['username'];
        $message = $matches['message'];

        return array(
            'id'             => md5(serialize($matches)),
            'channel'        => $channel,
            'datetime'       => $datetime,
            'username'       => $username,
            'nick'           => $nick,
            'message'        => preg_replace('/[\x00-\x1F\x7F]/', '', $message)
        );

    }

    /**
     * Returns a line number for a given channel and date
     *
     * @param string $channel
     * @param \DateTime $datetime
     *
     * @return int
     */
    private function lineNumberFor($channel, \DateTime $datetime)
    {
        $date = $datetime->format('Y-m-d');

        if (!(isset($this->channelLineNumbers[$channel]) && isset($this->channelLineNumbers[$channel][$date]))) {
            $line = $line = $this->solr->getLastLineNumberForChannelAndDate($channel, $datetime);
            $this->channelLineNumbers[$channel][$date] = $line ?: 0;
        }

        return ++$this->channelLineNumbers[$channel][$date];

    }

    /**
     * Gets a regex that matches an log format
     *
     * @return string The regular expression
     */
    public static function getLogFormatRegex()
    {
        return <<<EOF
        /^
        \[(?<date>\w+\s+\w+\s+\d+\s+\d+:\d+:\d+\s\d+)\]
        \s\#
        (?<channel>[^\s]*)\s
        (?<nick>[^\s]*)\s
        (?<username>[^@]*)@[^\s]*\s
        (?<message>.+)
        $/mx
EOF;
    }
}
