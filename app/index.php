<?php

use Tracy\Debugger;
use Tester\Assert;

$container = require __DIR__ . '/../app/bootstrap.php';

$database = $container->getByType('\Nette\Database\Context');

Debugger::enable(false);
        
\Tester\Environment::setup();

// nazev databaze, kde jsou tabulky
define('DB', 'test.');

define('TYPES', [1 => 'addressbook', 2 => 'search']);


class ACL 
{
    /** @var \Nette\Database\Context */
    private $database;
    
    /** @var Village */
    private $villageModel;
    
    /** @var UserAdmin */
    private $userAdminModel;
        
    public function __construct(Nette\DI\Container $container)
    {
        $this->database       = $container->getByType('\Nette\Database\Context');
        $this->villageModel   = new Village($this->database);
        $this->userAdminModel = new UserAdmin($this->database);
        
        
        // vytvoreni tabulek, pokud nejsou
        $this->database->query('
            CREATE TABLE IF NOT EXISTS '.DB.'`user_admin` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `name` varchar(32) COLLATE utf8mb4_bin NOT NULL,
              `addressbook` int(10) unsigned DEFAULT NULL,
              `search` int(10) unsigned DEFAULT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;');

        $this->database->query('
            CREATE TABLE IF NOT EXISTS '.DB.'`village` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `name` varchar(100) COLLATE utf8mb4_bin NOT NULL,
              `value` int(10) unsigned NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `value` (`value`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;');

        // inicializace/reset hodnot databaze
        $this->database->query('SET foreign_key_checks = 0');
        $this->database->query('TRUNCATE '.DB.'`user_admin`');
        $this->database->query('TRUNCATE '.DB.'`village`');
        $this->database->query('INSERT INTO '.DB.'`village` (`id`, `name`, `value`) VALUES
                                (1,	"Praha", 2),
                                (2,	"Brno",  4)');
        $this->database->query('INSERT INTO '.DB.'`user_admin` (`id`, `name`, `addressbook`, `search`) VALUES
                                (1,	"Adam",	2, 2),
                                (2,	"Bob",	4, 2),
                                (3,	"Cyril", '.array_sum([2,4]).', 4);');
        $this->database->query('SET foreign_key_checks = 1');
    }
    
    
    /**
     * 
     * @param int $user_id
     * @param array $grants
     */
    public function set(int $user_id, ?array $grants = [])
    {
        $this->userAdminModel->setGrants($user_id, $grants);
    }
    
    
    /**
     * 
     * @param int $user_id
     * @param string $type
     * @return array
     * @throws \InvalidArgumentException
     */
    public function get(int $user_id, string $type) : array
    {
        // kontrola, zda existuje pozadovana obec
        if (array_search($type, TYPES) === false)
        {
            throw new \InvalidArgumentException('Unknow type of rights');
        }
        
        return $this->userAdminModel->getRights($user_id, $type);
    }  
}


class Village
{
    /** @var \Nette\Database\Context */
    private $database;
    
    public function __construct(\Nette\Database\Context $database)
    {
        $this->database = $database;
    }
    
    
    /**
     * Seznam obci
     * @return array
     */
    public function getList() : array
    {
        return $this->database->fetchPairs('SELECT id, name FROM '.DB.'village ORDER BY name');
    }
    
    
    /**
     * Zalozeni nove obce s pravidly pro pridani opravneni u stavajicich uzivatelu
     * @param string $name
     * @return int
     */
    public function insert(string $name) : int
    {
        try
        {   
            $this->database->beginTransaction();
            
            $allGrantsValueOld = $this->database->fetchField('SELECT SUM(value) FROM '.DB.'village');
            
            $this->database->query('INSERT INTO '.DB.'village (name, value) SELECT ?, POWER(2, COUNT(id)+1) FROM '.DB.'village', $name);
            
            $village_id = $this->database->getInsertId();
            
            $allGrantsValueNew = $this->database->fetchField('SELECT SUM(value) FROM '.DB.'village');
            
            // automaticky má každý uživatel, co měl do té chvíle všechna práva na všechna města, také práva na nove mesto
            $this->database->query('UPDATE '.DB.'user_admin '
                                    . 'SET ', array_fill_keys(array_values(TYPES), $allGrantsValueNew), ' '
                                  . 'WHERE ('.implode(' + ', array_values(TYPES)).') = ? ', 
                                    $allGrantsValueOld * count(TYPES));
            
            // Zároveň ale pokud uživatel měl u jednoho práva (třeba Vyhledávač) práva na všechny města, získá právo Vyhledávač na nove mesto.
            foreach (TYPES as $key)
            {
                $this->database->query('UPDATE '.DB.'user_admin SET `'.$key.'` = ? WHERE `'.$key.'` = ?', $allGrantsValueNew, $allGrantsValueOld);
            }
            
            $this->database->commit();
            
            return $village_id;
            
        } catch (\Exception $e) {
            dumpe($e->getTrace());
            $this->database->rollBack();
        }
    }
}
    

class UserAdmin
{
    /** @var \Nette\Database\Context */
    private $database;
    
    public function __construct(\Nette\Database\Context $database)
    {
        $this->database = $database;
    }
    
    
    /**
     * 
     * @return \Nette\Database\Table\Selection
     */
    public function findAll() : Nette\Database\Table\Selection
    {
        return $this->database->table('user_admin');
    }
    
    
    /**
     * zalozeni uzivatele (ihned ma vsechna opravneni)
     * @param string $name
     * @return int
     */
    public function insert(string $name) : int
    {
        $this->database->query('INSERT INTO '.DB.'user_admin (name, '.implode(', ', array_values(TYPES)).') '
                             . 'SELECT ?'.str_repeat(', SUM(value)', count(TYPES)).' FROM '.DB.'village', $name);
        return $this->database->getInsertId();
    }
    
    
    
    /**
     * 
     * @param int $user_id
     * @param array $grants
     */
    public function setGrants(int $user_id, array $grants)
    {
        $checkedGrants = $this->checkGrants($grants);
        
        $privileges = [];
        foreach ($checkedGrants as $key => $villages)
        {
            $privileges[$key] = new Nette\Database\SqlLiteral('(SELECT SUM(`value`) FROM '.DB.'village WHERE id IN (?))', [array_keys($villages)]);
        }
        
        $this->database->query('UPDATE '.DB.'user_admin SET ? WHERE id = ?', $privileges, $user_id);
    }
    
    
    /**
     * 
     * @param int $user_id
     * @param string $type
     * @return array
     */
    public function getRights(int $user_id, string $type) : array
    {        
        return $this->database->query(
                'SELECT id, name FROM '.DB.'village WHERE 
                    (SELECT user_admin.`'.$type.'` FROM '.DB.'user_admin WHERE user_admin.id = ?) & village.value = village.value '
            . 'ORDER BY id', 
                $user_id)->fetchPairs('id', 'name');
    }
    
    
    /**
     * public metoda jen kvuli moznosti jednoduse otestovat funkcnost
     * @param array $grants
     * @return array Vraci pouze klice s hodnotou true
     */
    public function checkGrants(array $grants) : array
    {
        $filtered = $this->array_filter_recursive($grants);
        
        $villages = $this->database->fetchPairs('SELECT id FROM '.DB.'village');

        // Pokud pomyslný formulář bude kompletně nezaškrtnutý, musí uživatel dostat kompletní neomezená práva.
        if (array_filter($filtered) === [])
        {
            // vytvoreni multidimensionalniho pole [opravneni => [obec1 => true, obec2 => true, ...], ...]
            return array_fill_keys(TYPES, array_fill_keys($villages, true));
        }
        
        foreach ($filtered as $key => $values)
        {
            // Pokud bude pro libovolné právo např. Adresář celý sloupec měst nezaškrtnutý, získá úživatel taktéž pro dané právo přístup ke všem městům.
            if (empty($values))
            {
                // naplneni prislusne casi opravneni kladnymi hodnotami
                $filtered[$key] = array_fill_keys($villages, true);
            }
        }
        
        return $filtered;
    }
    

    /**
     * Recursively filter an array
     *
     * @param array $array
     * @param callable $callback
     *
     * @return array
     */
    private function array_filter_recursive(array $array, callable $callback = null) : array
    {
        $array = is_callable($callback) ? array_filter($array, $callback) : array_filter($array);
        foreach ($array as &$value) 
        {
            if (is_array($value)) 
            {
                $value = call_user_func([$this, __FUNCTION__], $value, $callback );
            }
        }

        return $array;
    }
}




$acl = new ACL($container);

// test na spravny typ opravneni
Assert::exception(function() use ($acl) { $acl->get(1, 'invoices'); }, \InvalidArgumentException::class, 'Unknow type of rights');

// uživatel Adam má v Praze obě práva ("Adresář" a "Vyhledávač") a v Brně ani jedno. 
Assert::same([1 => 'Praha'], $acl->get(1, 'addressbook'));
Assert::same([1 => 'Praha'], $acl->get(1, 'search'));

// Uživatel Bob má v Brně pouze Adresář a v Praze pouze Vyhledávač.
Assert::same([2 => 'Brno'], $acl->get(2, 'addressbook'));
Assert::same([1 => 'Praha'], $acl->get(2, 'search'));

// Uživatel Cyril má Adresář v obou městech a Vyhledávač jenom v Brně.
Assert::equal([1 => 'Praha', 2 => 'Brno'], $acl->get(3, 'addressbook'));
Assert::same([2 => 'Brno'], $acl->get(3, 'search'));

// Uživatel Derek není vůbec v tabulce `user_admin` a tím pádem nemá žádná práva. 
// Tj. pokud je uživatel uveden v tabulce user_admin nemůže nemít nějaká práva, tj. musí mít buď všechna nebo nějak omezená, 
// ale nelze/není nutné, aby šlo nastavit, že uživatel nemá žádné právo.
Assert::same([], $acl->get(4, 'search'));
Assert::same([], $acl->get(4, 'addressbook'));


// Pokud nového uživatele Freda přidám do `user_admin`, má bez jakékoliv další akce 
// (ať už na úrovní aplikace či DB/trigger) automaticky všechna práva na všechna města.
$userAdminModel = new UserAdmin($database);
$fred_id = $userAdminModel->insert('Fred');

Assert::equal([1 => 'Praha', 2 => 'Brno'], $acl->get($fred_id, 'addressbook'));
Assert::equal([1 => 'Praha', 2 => 'Brno'], $acl->get($fred_id, 'search'));


// Pokud do village přidám nové město Ostrava, automaticky bez jakékoliv další akce 
// (ať už na úrovní aplikace či DB/trigger) má každý uživatel, co měl do té chvíle 
// všechna práva na všechny města, také práva na Ostravu. Noapak, uživatel, co měl 
// nějaké omezení libovolného práva (např Adresář) v nějakém městě, tak nesmí získat 
// právo na Ostravu (pro Adresář). Zároveň ale pokud uživatel měl u jednoho práva 
// (třeba Vyhledávač) práva na všechny města, získá právo Vyhledávač na Ostravu.

// pomocne hodnoty pro nasledujici testy
$adam  = ['addressbook' => $acl->get(1, 'addressbook'), 'search' => $acl->get(1, 'search')];
$bob   = ['addressbook' => $acl->get(2, 'addressbook'), 'search' => $acl->get(2, 'search')];
$cyril = ['addressbook' => $acl->get(3, 'addressbook'), 'search' => $acl->get(3, 'search')];
$derek = ['addressbook' => $acl->get(0, 'addressbook'), 'search' => $acl->get(0, 'search')];
$fred  = ['addressbook' => $acl->get($fred_id, 'addressbook'), 'search' => $acl->get($fred_id, 'search')];

$villageModel = new Village($database);
$ostrava_id = $villageModel->insert('Ostrava');

// bylo zalozeno nove mesto?
Assert::equal([1 => 'Praha', 2 => 'Brno', $ostrava_id => 'Ostrava'], $villageModel->getList());

// Adam: nic nezíská
Assert::equal($adam['addressbook'], $acl->get(1, 'addressbook'));
Assert::equal($adam['search'], $acl->get(1, 'search'));

//Bob: nic nezíská
Assert::equal($bob['addressbook'], $acl->get(2, 'addressbook'));
Assert::equal($bob['search'], $acl->get(2, 'search'));

//Cyril: získá právo Adresář pro Ostravu
$cyril['addressbook'][$ostrava_id] = 'Ostrava';
Assert::equal($cyril['addressbook'], $acl->get(3, 'addressbook'));
Assert::equal($cyril['search'], $acl->get(3, 'search'));

//Derek: nic nezíská
Assert::equal($derek['addressbook'], $acl->get(0, 'addressbook'));
Assert::equal($derek['search'], $acl->get(0, 'search'));

//Fred: získá právo Adresář i Vyhledávač pro Ostravu
$fred['addressbook'][$ostrava_id] = 'Ostrava';
$fred['search'][$ostrava_id] = 'Ostrava';
Assert::equal($fred['addressbook'], $acl->get($fred_id, 'addressbook'));
Assert::equal($fred['search'], $acl->get($fred_id, 'search'));


// Pokud pomyslný formulář bude kompletně nezaškrtnutý, musí uživatel dostat kompletní neomezená práva
// -> kontrola metody, ktera to zarizuje
Assert::equal(['addressbook' => [1 => true, 2 => true, $ostrava_id => true], 'search' => [1 => true, 2 => true, $ostrava_id => true]], $userAdminModel->checkGrants(['addressbook' => [1 => false, 2 => false], 'search' => [1 => false, 2 => false]]));
Assert::equal(['addressbook' => [1 => true, 2 => true, $ostrava_id => true], 'search' => [1 => true, 2 => true, $ostrava_id => true]], $userAdminModel->checkGrants(['addressbook' => [], 'search' => [1 => false, 2 => false]]));
Assert::equal(['addressbook' => [1 => true, 2 => true, $ostrava_id => true], 'search' => [1 => true, 2 => true, $ostrava_id => true]], $userAdminModel->checkGrants(['addressbook' => [], 'search' => []]));
Assert::equal(['addressbook' => [1 => true, 2 => true, $ostrava_id => true], 'search' => [1 => true, 2 => true, $ostrava_id => true]], $userAdminModel->checkGrants(['addressbook' => []]));
Assert::equal(['addressbook' => [1 => true, 2 => true, $ostrava_id => true], 'search' => [1 => true, 2 => true, $ostrava_id => true]], $userAdminModel->checkGrants([]));

// Pokud bude pro libovolné právo např. Adresář celý sloupec měst nezaškrtnutý, získá úživatel taktéž pro dané právo přístup ke všem městům
Assert::equal(['addressbook' => [1 => true], 'search' => [1 => true, 2 => true, $ostrava_id => true]], $userAdminModel->checkGrants(['addressbook' => [1 => true, 2 => false], 'search' => [1 => false, 2 => false]]));


// test nastavovani opravneni
$acl->set(1, ['addressbook' => [1 => true, 2 => false, $ostrava_id => true], 'search' => [1 => false, 2 => false]]);
Assert::equal([1 => 'Praha', $ostrava_id => 'Ostrava'], $acl->get(1, 'addressbook'));
Assert::equal([1 => 'Praha', 2 => 'Brno', $ostrava_id => 'Ostrava'], $acl->get(1, 'search'));

$acl->set(1, ['addressbook' => [1 => true, 2 => false, $ostrava_id => false], 'search' => [1 => true, 2 => false, $ostrava_id => false]]);
Assert::equal([1 => 'Praha'], $acl->get(1, 'addressbook'));
Assert::equal([1 => 'Praha'], $acl->get(1, 'search'));

$acl->set(1, ['addressbook' => [1 => false, 2 => true]]);
Assert::equal([2 => 'Brno'], $acl->get(1, 'addressbook'));
Assert::equal([1 => 'Praha'], $acl->get(1, 'search'));

$acl->set(1, ['addressbook' => [], 'search' => []]);
Assert::equal([1 => 'Praha', 2 => 'Brno', $ostrava_id => 'Ostrava'], $acl->get(1, 'addressbook'));
Assert::equal([1 => 'Praha', 2 => 'Brno', $ostrava_id => 'Ostrava'], $acl->get(1, 'search'));




$users = $userAdminModel->findAll();
foreach ($users as $user)
{
    echo PHP_EOL.$user->name.PHP_EOL.'==========='.PHP_EOL;
    foreach (TYPES as $type){
        dump($acl->get($user->id, $type));   
    }
    echo PHP_EOL;
}