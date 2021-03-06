<?php

namespace CurrencyConverter\Controller\Component;

use Cake\Controller\Component;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Cake\Database\Schema\TableSchema;
use Cake\i18n\Time;

class CurrencyConverterComponent extends Component
{
    private $fromCurrency;

    private $toCurrency;

    private $amount;

    private $hourDifference;

    private $saveIntoDb;

    private $dataSource;

    private $rate;

    private $currencyTable;

    /**
     * Convertion function
     *
     * @param string $fromCurrency the starting currency that user wants to convert to.
     * @param string $toCurrency the ending currency that user wants to convert to.
     * @param float $amount the amount to convert.
     * @param boolean $saveIntoDb if develop wants to store convertion rate for use it without resending data to yahoo service.
     * @param int $hourDifference the hour difference to check if the last convertion is passed, if yes make a new call to yahoo finance api.
     * @param string $dataSource which dataSOurce need to use
     * @return float the total amount converted into the new currency
     */
    public function convert(
        $fromCurrency,
        $toCurrency,
        $amount,
        $saveIntoDb = true,
        $hourDifference = 1,
        $dataSource = 'default'
    ) {
        $this->fromCurrency = $fromCurrency;
        $this->toCurrency = $toCurrency;
        $this->amount = $amount;
        $this->saveIntoDb = (bool)$saveIntoDb;
        $this->hourDifference = $hourDifference;
        $this->dataSource = $dataSource;
        $this->rate = 0;

        $this->fixFromToCurrency();

        if ($this->saveIntoDb === true) {
            $this->currencyTable = TableRegistry::get('CurrencyConverter', [
                'className' => 'CurrencyConverter\Model\Table\CurrencyConvertersTable',
                'table' => 'currency_converter'
            ]);

            $this->ensureIfExistTable();
            $this->saveIntoDatabase();

            return $this->calculateValue();
        }

        $this->rate = $this->getRates();

        return $this->calculateValue();

        return number_format((double)$this->amount, 2, '.', '');
    }

    private function fixFromToCurrency()
    {
        if ($this->fromCurrency == "PDS"){
            $this->fromCurrency = "GBP";
        }

        if ($this->toCurrency == "PDS"){
            $this->toCurrency = "GBP";
        }
    }

    private function saveIntoDatabase()
    {
        $query = $this->currencyTable->find('all')
            ->where(['fromCurrency' => $this->fromCurrency, 'toCurrency' => $this->toCurrency ]);

        $query->enableHydration(false);
        $result =  $query->toArray();

        foreach ($result as $row){
            $lastUpdated = $row['modified'];

            $now = date('Y-m-d H:i:s');
            $dStart = new \DateTime($now);
            $diff = $dStart->diff($lastUpdated);

            if ($this->ensureNeedToUpdateDatabase($diff, $row)) {
                $this->updateDatabase($row);
            } else {
                $this->rate = $row['rates'];
            }
        }

        if (count($result) <= 0) {
            $this->insertIntoDatabase();
        }
    }

    private function updateDatabase($row)
    {
        $this->rate = $this->getRates();
        $now = new Time('now');

        $data = [
            'fromCurrency'=> $this->fromCurrency,
            'toCurrency'  => $this->toCurrency,
            'rates'       => $this->rate,
            'modified'    => $now,
        ];

        $entity = $this->currencyTable->get($row['id']);
        $this->currencyTable->patchEntity($entity, $data);
        $this->currencyTable->save($entity);
    }

    private function insertIntoDatabase()
    {
        $this->rate = $this->getRates();
        $now = new Time('now');

        $data = [
            'fromCurrency' => $this->fromCurrency,
            'toCurrency'   => $this->toCurrency,
            'rates'        => $this->rate,
            'created'      => $now,
            'modified'     => $now,
        ];

        $entity = $this->currencyTable->newEntity($data);
        $this->currencyTable->save($entity);
    }

    private function ensureNeedToUpdateDatabase($diff, $row)
    {
        return (
            ((int)$diff->y >= 1) ||
            ((int)$diff->m >= 1) ||
            ((int)$diff->d >= 1) ||
            ((int)$diff->h >= $this->hourDifference) ||
            ((double)$row['rates'] == 0)
        );
    }

    private function getRates()
    {
        if ($this->fromCurrency == $this->toCurrency) {
            return 1;
        } else {
            $url = 'http://api.fixer.io/latest?symbols=' . $this->toCurrency .'&base='.$this->fromCurrency;

            $handle = file_get_contents($url);

            if ($handle) {
                $result = json_decode($handle, true);
            }

            if(isset($result)){
                $rates = $result['rates'];
                return floatval(reset($rates));
            }
        }

        return $this->rate = 0;
    }

    private function ensureIfExistTable()
    {
        $autoIncrement = 'AUTO_INCREMENT';

        $db = ConnectionManager::get($this->dataSource);
        $config = $db->config();

        if (strpos($config['driver'], 'Sqlite') !== false) {
            $autoIncrement = 'AUTOINCREMENT';
        }

        $sql = 'CREATE TABLE IF NOT EXISTS `currency_converters` (
          `id` integer PRIMARY KEY ' . $autoIncrement . ',
          `fromCurrency` varchar(5) NOT NULL,
          `toCurrency` varchar(5) NOT NULL,
          `rates` varchar(10) NOT NULL,
          `created` datetime NOT NULL,
          `modified` datetime NOT NULL
        );';

        return $db->query($sql);
    }

    private function calculateValue()
    {
        $value = (double)$this->rate * (double)$this->amount;
        return number_format((double)$value, 2, '.', '');
    }
}
