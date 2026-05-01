<?php
/**
 * MyFeeds Search Engine v2 (Phase 16.2)
 * 
 * FULLTEXT-based product search with:
 * - Synonym expansion (DE/EN, Singular/Plural, Gender, Clothing, Colors, Accessories)
 * - German stemming (adjective/plural endings)
 * - Umlaut normalization (ä→ae, ö→oe, ü→ue, ß→ss + reverse)
 * - Stop word removal (DE + EN)
 * - Gender-aware filtering
 * - 3-tier weighted scoring (1000/100/10 base)
 * - Size-variant deduplication
 * - LIKE fallback for short tokens (< 4 chars)
 * 
 * @since 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Search_Engine {

    /** @var array|null Cached known brands from DB (lowercase) */
    private static $known_brands_cache = null;

    // =========================================================================
    // SYNONYM MAP
    // =========================================================================

    private static function get_synonyms() {
        return array(
            // Gender terms
            'women'    => array('damen', 'frau', 'frauen', 'woman', 'dame', 'female', 'lady', 'ladies'),
            'woman'    => array('damen', 'frau', 'frauen', 'women', 'dame', 'female', 'lady', 'ladies'),
            'damen'    => array('women', 'woman', 'frau', 'frauen', 'dame', 'female', 'lady', 'ladies'),
            'frau'     => array('women', 'woman', 'damen', 'frauen', 'dame', 'female', 'lady', 'ladies'),
            'frauen'   => array('women', 'woman', 'damen', 'frau', 'dame', 'female', 'lady', 'ladies'),
            'dame'     => array('women', 'woman', 'damen', 'frau', 'frauen', 'female', 'lady', 'ladies'),
            'female'   => array('women', 'woman', 'damen', 'frau', 'frauen', 'dame', 'lady', 'ladies'),
            'lady'     => array('women', 'woman', 'damen', 'frau', 'frauen', 'dame', 'female', 'ladies'),
            'ladies'   => array('women', 'woman', 'damen', 'frau', 'frauen', 'dame', 'female', 'lady'),

            'men'      => array('man', 'herren', 'mann', 'männer', 'herr', 'male', 'gentleman', 'gentlemen'),
            'man'      => array('men', 'herren', 'mann', 'männer', 'herr', 'male'),
            'herren'   => array('man', 'men', 'mann', 'männer', 'herr', 'male', 'gentleman', 'gentlemen'),
            'mann'     => array('man', 'men', 'herren', 'männer', 'herr', 'male', 'gentleman', 'gentlemen'),
            'männer'   => array('man', 'men', 'herren', 'mann', 'herr', 'male', 'gentleman', 'gentlemen'),
            'herr'     => array('man', 'men', 'herren', 'mann', 'männer', 'male', 'gentleman', 'gentlemen'),
            'male'     => array('man', 'men', 'herren', 'mann', 'männer', 'herr', 'gentleman', 'gentlemen'),
            'gentleman' => array('man', 'men', 'herren', 'mann', 'männer', 'herr', 'male', 'gentlemen'),
            'gentlemen' => array('man', 'men', 'herren', 'mann', 'männer', 'herr', 'male', 'gentleman'),

            // Children/Kids
            'girls'    => array('mädchen', 'girl'),
            'girl'     => array('mädchen', 'girls'),
            'mädchen'  => array('girls', 'girl'),
            'boys'     => array('jungen', 'boy', 'junge'),
            'boy'      => array('jungen', 'boys', 'junge'),
            'junge'    => array('boys', 'boy', 'jungen'),
            'jungen'   => array('boys', 'boy', 'junge'),
            'kids'     => array('kinder', 'children', 'kid', 'kind'),
            'kid'      => array('kinder', 'children', 'kids', 'kind'),
            'kind'     => array('kinder', 'children', 'kids', 'kid'),
            'kinder'   => array('kids', 'children', 'kid', 'kind'),
            'children' => array('kinder', 'kids', 'kid', 'kind'),

            // Clothing
            'shirt'    => array('hemd', 'shirts', 'hemden'),
            'shirts'   => array('hemd', 'shirt', 'hemden'),
            'hemd'     => array('shirt', 'shirts', 'hemden'),
            'hemden'   => array('shirt', 'shirts', 'hemd'),
            'jacket'   => array('jacke', 'jackets', 'jacken'),
            'jackets'  => array('jacke', 'jacket', 'jacken'),
            'jacke'    => array('jacket', 'jackets', 'jacken'),
            'jacken'   => array('jacket', 'jackets', 'jacke'),
            'shoe'     => array('schuh', 'shoes', 'schuhe', 'sneaker', 'sneakers', 'boot', 'boots', 'stiefel'),
            'shoes'    => array('schuh', 'shoe', 'schuhe', 'sneaker', 'sneakers', 'boot', 'boots', 'stiefel'),
            'schuh'    => array('shoe', 'shoes', 'schuhe', 'sneaker', 'sneakers', 'boot', 'boots', 'stiefel'),
            'schuhe'   => array('shoe', 'shoes', 'schuh', 'sneaker', 'sneakers', 'boot', 'boots', 'stiefel'),
            'sneaker'  => array('sneakers', 'turnschuh', 'turnschuhe', 'sportschuh', 'sportschuhe', 'shoe', 'shoes', 'schuh', 'schuhe'),
            'sneakers' => array('sneaker', 'turnschuh', 'turnschuhe', 'sportschuh', 'sportschuhe', 'shoe', 'shoes', 'schuh', 'schuhe'),
            'turnschuh' => array('sneaker', 'sneakers', 'turnschuhe', 'sportschuh', 'sportschuhe'),
            'turnschuhe' => array('sneaker', 'sneakers', 'turnschuh', 'sportschuh', 'sportschuhe'),
            'sportschuh' => array('sneaker', 'sneakers', 'turnschuh', 'turnschuhe', 'sportschuhe'),
            'sportschuhe' => array('sneaker', 'sneakers', 'turnschuh', 'turnschuhe', 'sportschuh'),
            'pant'     => array('hose', 'pants', 'hosen'),
            'pants'    => array('hose', 'pant', 'hosen'),
            'hose'     => array('pant', 'pants', 'hosen'),
            'hosen'    => array('pant', 'pants', 'hose'),
            'dress'    => array('kleid', 'dresses', 'kleider'),
            'dresses'  => array('kleid', 'dress', 'kleider'),
            'kleid'    => array('dress', 'dresses', 'kleider'),
            'kleider'  => array('dress', 'dresses', 'kleid'),
            'hat'      => array('hut', 'hats', 'hüte'),
            'hats'     => array('hut', 'hat', 'hüte'),
            'hut'      => array('hat', 'hats', 'hüte'),
            'hüte'     => array('hat', 'hats', 'hut'),
            'cap'      => array('mütze', 'caps', 'mützen'),
            'caps'     => array('mütze', 'cap', 'mützen'),
            'mütze'    => array('cap', 'caps', 'mützen'),
            'mützen'   => array('cap', 'caps', 'mütze'),
            'boot'     => array('boots', 'stiefel', 'shoe', 'shoes', 'schuh', 'schuhe'),
            'boots'    => array('boot', 'stiefel', 'shoe', 'shoes', 'schuh', 'schuhe'),
            'stiefel'  => array('boot', 'boots', 'shoe', 'shoes', 'schuh', 'schuhe'),
            'sandal'   => array('sandals', 'sandale', 'sandalen'),
            'sandals'  => array('sandal', 'sandale', 'sandalen'),
            'sandale'  => array('sandal', 'sandals', 'sandalen'),
            'sandalen' => array('sandal', 'sandals', 'sandale'),
            'top'      => array('tops', 'oberteil', 'oberteile', 'shirt', 'shirts'),
            'tops'     => array('top', 'oberteil', 'oberteile', 'shirt', 'shirts'),
            'oberteil' => array('oberteile', 'top', 'tops', 'shirt', 'shirts'),
            'oberteile'=> array('oberteil', 'top', 'tops', 'shirt', 'shirts'),
            // HOODIE-Familie (mit Kapuze) – NICHT mit sweater verlinkt
            'hoodie'          => array('hoodies', 'hoody', 'hoodys', 'kapuzenpullover', 'kapuzenpulli'),
            'hoodies'         => array('hoodie', 'hoody', 'hoodys', 'kapuzenpullover', 'kapuzenpulli'),
            'hoody'           => array('hoodie', 'hoodies', 'hoodys', 'kapuzenpullover', 'kapuzenpulli'),
            'hoodys'          => array('hoodie', 'hoodies', 'hoody', 'kapuzenpullover', 'kapuzenpulli'),
            'kapuzenpullover' => array('hoodie', 'hoodies', 'hoody', 'hoodys', 'kapuzenpulli'),
            'kapuzenpulli'    => array('hoodie', 'hoodies', 'hoody', 'hoodys', 'kapuzenpullover'),

            // SWEATER-Familie (ohne Kapuze) – NICHT mit hoodie verlinkt
            'sweater'  => array('sweaters', 'strickpullover', 'strickpulli'),
            'sweaters' => array('sweater', 'strickpullover', 'strickpulli'),
            'strickpullover' => array('sweater', 'sweaters', 'strickpulli'),
            'strickpulli'    => array('sweater', 'sweaters', 'strickpullover'),

            // PULLOVER/PULLI (Oberbegriff) – nur untereinander verlinkt
            'pullover' => array('pulli', 'pullis', 'pullie', 'pullies'),
            'pulli'    => array('pullis', 'pullie', 'pullies', 'pullover'),
            'pullis'   => array('pulli', 'pullie', 'pullies', 'pullover'),
            'pullie'   => array('pulli', 'pullis', 'pullies', 'pullover'),
            'pullies'  => array('pulli', 'pullis', 'pullie', 'pullover'),
            'coat'     => array('coats', 'mantel', 'mäntel'),
            'coats'    => array('coat', 'mantel', 'mäntel'),
            'mantel'   => array('mäntel', 'coat', 'coats'),
            'mäntel'   => array('mantel', 'coat', 'coats'),
            'skirt'    => array('skirts', 'rock', 'röcke'),
            'skirts'   => array('skirt', 'rock', 'röcke'),
            'rock'     => array('röcke', 'skirt', 'skirts'),
            'röcke'    => array('rock', 'skirt', 'skirts'),
            'suit'     => array('suits', 'anzug', 'anzüge'),
            'suits'    => array('suit', 'anzug', 'anzüge'),
            'anzug'    => array('anzüge', 'suit', 'suits'),
            'anzüge'   => array('anzug', 'suit', 'suits'),
            'jeans'    => array('jean'),
            'jean'     => array('jeans'),
            'bikini'   => array('bikinis', 'swimwear', 'bademode', 'badeanzug'),
            'bikinis'  => array('bikini', 'swimwear', 'bademode', 'badeanzug'),
            'swimwear' => array('bademode', 'bikini', 'bikinis', 'badeanzug', 'swimsuit', 'swimsuits'),
            'bademode' => array('swimwear', 'bikini', 'bikinis', 'badeanzug', 'swimsuit', 'swimsuits'),
            'badeanzug'=> array('badeanzüge', 'swimwear', 'bademode', 'bikini', 'bikinis', 'swimsuit', 'swimsuits'),

            // Backpack/Rucksack
            'backpack'  => array('backpacks', 'rucksack', 'rucksäcke', 'rucksaecke'),
            'backpacks' => array('backpack', 'rucksack', 'rucksäcke', 'rucksaecke'),
            'rucksack'  => array('rucksäcke', 'rucksaecke', 'backpack', 'backpacks'),
            'rucksäcke' => array('rucksack', 'rucksaecke', 'backpack', 'backpacks'),
            'rucksaecke'=> array('rucksack', 'rucksäcke', 'backpack', 'backpacks'),

            // Wallet/Purse
            'wallet'     => array('wallets', 'geldbörse', 'geldboerse', 'portemonnaie'),
            'wallets'    => array('wallet', 'geldbörse', 'geldboerse', 'portemonnaie'),
            'geldbörse'  => array('wallet', 'wallets', 'geldboerse', 'portemonnaie'),
            'geldboerse' => array('wallet', 'wallets', 'geldbörse', 'portemonnaie'),
            'portemonnaie' => array('wallet', 'wallets', 'geldbörse', 'geldboerse'),
            'purse'      => array('purses', 'handtasche', 'handtaschen'),
            'purses'     => array('purse', 'handtasche', 'handtaschen'),
            'handtasche' => array('handtaschen', 'purse', 'purses'),
            'handtaschen'=> array('handtasche', 'purse', 'purses'),

            // Shorts
            'shorts'     => array('short'),
            'short'      => array('shorts'),

            // T-Shirt variants
            'tshirt'     => array('t-shirt', 'tee', 'shirt'),
            't-shirt'    => array('tshirt', 'tee', 'shirt'),
            'tee'        => array('tshirt', 't-shirt', 'shirt'),

            // Colors
            'black'    => array('schwarz'),
            'schwarz'  => array('black'),
            'white'    => array('weiß', 'weiss'),
            'weiß'     => array('white', 'weiss'),
            'weiss'    => array('white', 'weiß'),
            'red'      => array('rot'),
            'rot'      => array('red'),
            'blue'     => array('blau'),
            'blau'     => array('blue'),
            'green'    => array('grün', 'gruen'),
            'grün'     => array('green', 'gruen'),
            'gruen'    => array('green', 'grün'),
            'yellow'   => array('gelb'),
            'gelb'     => array('yellow'),
            'brown'    => array('braun'),
            'braun'    => array('brown'),
            'gray'     => array('grau', 'grey'),
            'grey'     => array('grau', 'gray'),
            'grau'     => array('gray', 'grey'),

            // Accessories
            'bag'      => array('tasche', 'bags', 'taschen'),
            'bags'     => array('tasche', 'bag', 'taschen'),
            'tasche'   => array('bag', 'bags', 'taschen'),
            'taschen'  => array('bag', 'bags', 'tasche'),
            'watch'    => array('uhr', 'watches', 'uhren'),
            'watches'  => array('uhr', 'watch', 'uhren'),
            'uhr'      => array('watch', 'watches', 'uhren'),
            'uhren'    => array('watch', 'watches', 'uhr'),
            'belt'     => array('gürtel', 'belts'),
            'belts'    => array('gürtel', 'belt'),
            'gürtel'   => array('belt', 'belts'),

            // === MATERIALIEN ===
            'leather'    => array('leder'),
            'leder'      => array('leather'),
            'wool'       => array('wolle'),
            'wolle'      => array('wool'),
            'silk'       => array('seide'),
            'seide'      => array('silk'),
            'cotton'     => array('baumwolle'),
            'baumwolle'  => array('cotton'),
            'linen'      => array('leinen'),
            'leinen'     => array('linen'),
            'velvet'     => array('samt'),
            'samt'       => array('velvet'),
            'suede'      => array('wildleder'),
            'wildleder'  => array('suede'),
            'cashmere'   => array('kaschmir'),
            'kaschmir'   => array('cashmere'),
            'corduroy'   => array('cord', 'kord'),
            'cord'       => array('corduroy', 'kord'),
            'kord'       => array('corduroy', 'cord'),

            // === ACCESSOIRES (fehlende) ===
            'glove'      => array('gloves', 'handschuh', 'handschuhe'),
            'gloves'     => array('glove', 'handschuh', 'handschuhe'),
            'handschuh'  => array('handschuhe', 'glove', 'gloves'),
            'handschuhe' => array('handschuh', 'glove', 'gloves'),
            'scarf'      => array('scarves', 'schal', 'schals', 'tuch'),
            'scarves'    => array('scarf', 'schal', 'schals', 'tuch'),
            'schal'      => array('schals', 'tuch', 'scarf', 'scarves'),
            'sunglasses' => array('sonnenbrille', 'sonnenbrillen'),
            'sonnenbrille' => array('sonnenbrillen', 'sunglasses'),
            'sonnenbrillen' => array('sonnenbrille', 'sunglasses'),
            'jewelry'    => array('jewellery', 'schmuck'),
            'jewellery'  => array('jewelry', 'schmuck'),
            'schmuck'    => array('jewelry', 'jewellery'),
            'necklace'   => array('necklaces', 'kette', 'ketten', 'halskette'),
            'kette'      => array('ketten', 'halskette', 'necklace', 'necklaces'),
            'bracelet'   => array('bracelets', 'armband', 'armbänder'),
            'armband'    => array('armbänder', 'bracelet', 'bracelets'),
            'earring'    => array('earrings', 'ohrring', 'ohrringe'),
            'ohrring'    => array('ohrringe', 'earring', 'earrings'),
            'ring'       => array('rings', 'ringe'),
            'rings'      => array('ring', 'ringe'),
            'ringe'      => array('ring', 'rings'),
            'beanie'     => array('beanies'),
            'beanies'    => array('beanie'),
            'headband'   => array('headbands', 'stirnband', 'stirnbänder'),
            'stirnband'  => array('stirnbänder', 'headband', 'headbands'),
            'socks'      => array('sock', 'socken', 'socke', 'strümpfe'),
            'socken'     => array('socke', 'socks', 'sock', 'strümpfe'),
            'tie'        => array('ties', 'krawatte', 'krawatten'),
            'krawatte'   => array('krawatten', 'tie', 'ties'),

            // === KLEIDUNG (fehlende) ===
            'vest'       => array('vests', 'weste', 'westen'),
            'weste'      => array('westen', 'vest', 'vests'),
            'blazer'     => array('blazers', 'sakko', 'sakkos'),
            'sakko'      => array('sakkos', 'blazer', 'blazers'),
            'cardigan'   => array('cardigans', 'strickjacke', 'strickjacken'),
            'strickjacke' => array('strickjacken', 'cardigan', 'cardigans'),
            'tracksuit'  => array('tracksuits', 'trainingsanzug', 'trainingsanzüge', 'jogginghose'),
            'trainingsanzug' => array('trainingsanzüge', 'tracksuit', 'tracksuits'),
            'jogginghose' => array('jogginghosen', 'sweatpants', 'tracksuit'),
            'sweatpants' => array('jogginghose', 'jogginghosen', 'trackpants'),
            'leggings'   => array('legging', 'leggins'),
            'legging'    => array('leggings', 'leggins'),
            'polo'       => array('polos', 'poloshirt', 'poloshirts'),
            'poloshirt'  => array('poloshirts', 'polo', 'polos'),
            'tank'       => array('tanktop', 'tanktops'),
            'tanktop'    => array('tanktops', 'tank'),
            'parka'      => array('parkas'),
            'windbreaker' => array('windbreakers', 'windjacke', 'windjacken'),
            'windjacke'  => array('windjacken', 'windbreaker', 'windbreakers'),
            'sweatshirt' => array('sweatshirts'),
            'overall'    => array('overalls', 'jumpsuit', 'jumpsuits'),
            'jumpsuit'   => array('jumpsuits', 'overall', 'overalls'),
            'underwear'  => array('unterwäsche'),
            'unterwäsche' => array('underwear'),
            'pajamas'    => array('pyjamas', 'schlafanzug'),
            'schlafanzug' => array('pajamas', 'pyjamas'),
            'swimsuit'   => array('swimsuits', 'badeanzug', 'badeanzüge', 'swimwear', 'bademode'),
            'swimsuits'  => array('swimsuit', 'badeanzug', 'badeanzüge', 'swimwear', 'bademode'),
            'badeanzüge' => array('badeanzug', 'swimsuit', 'swimsuits', 'swimwear', 'bademode'),
            'trunks'     => array('badehose', 'badehosen'),
            'badehose'   => array('badehosen', 'trunks'),
            'badehosen'  => array('badehose', 'trunks'),

            // === SCHUHE (fehlende) ===
            'loafer'     => array('loafers', 'slipper', 'slippers'),
            'loafers'    => array('loafer', 'slipper', 'slippers'),
            'slipper'    => array('slippers', 'loafer', 'loafers'),
            'slippers'   => array('slipper', 'loafer', 'loafers'),
            'heel'       => array('heels', 'absatzschuh', 'absatzschuhe', 'pumps'),
            'heels'      => array('heel', 'absatzschuh', 'absatzschuhe', 'pumps'),
            'pumps'      => array('pump', 'heel', 'heels'),
            'flip-flop'  => array('flip-flops', 'flipflop', 'flipflops', 'zehentrenner'),
            'zehentrenner' => array('flip-flop', 'flip-flops', 'flipflop', 'flipflops'),
            'slide'      => array('slides', 'badelatschen'),
            'slides'     => array('slide', 'badelatschen'),
        );
    }

    // =========================================================================
    // GENDER TERMS
    // =========================================================================

    private static function get_male_terms() {
        return array('man', 'men', 'herren', 'mann', 'männer', 'herr', 'male', 'gentleman', 'gentlemen', 'boys', 'boy', 'junge', 'jungen');
    }

    private static function get_female_terms() {
        return array('women', 'damen', 'frau', 'frauen', 'woman', 'dame', 'female', 'lady', 'ladies', 'girls', 'girl', 'mädchen');
    }

    /**
     * Check if a token is a gender term (directly or via synonym).
     */
    private static function is_gender_token($token) {
        static $gender_set = null;
        if ($gender_set === null) {
            $gender_set = array_flip(array_merge(self::get_male_terms(), self::get_female_terms()));
        }
        return isset($gender_set[$token]);
    }

    // =========================================================================
    // STOP WORDS (Phase 16.2)
    // =========================================================================

    private static function get_stop_words() {
        return array(
            // Deutsch
            'der', 'die', 'das', 'den', 'dem', 'des',
            'ein', 'eine', 'einer', 'eines', 'einem', 'einen',
            'und', 'oder', 'aber', 'mit', 'für', 'fur', 'von', 'vom', 'zum', 'zur',
            'auf', 'aus', 'bei', 'bis', 'nach', 'vor', 'seit',
            'in', 'im', 'an', 'am', 'um',
            'ist', 'sind', 'hat', 'haben', 'wird', 'werden',
            'nicht', 'kein', 'keine', 'keinen', 'keiner',
            'ich', 'du', 'er', 'sie', 'es', 'wir', 'ihr',
            'mein', 'dein', 'sein', 'unser', 'euer',
            'auch', 'noch', 'schon', 'sehr', 'nur', 'mal',
            'dann', 'denn', 'doch', 'dort', 'hier', 'jetzt', 'kann', 'mehr',
            'muss', 'nun', 'ob', 'weil', 'wenn', 'wie', 'wo',
            'über', 'ueber', 'unter', 'zwischen', 'gegen', 'ohne', 'während',
            // Englisch
            'the', 'a', 'an', 'and', 'or', 'but', 'with', 'for', 'from',
            'of', 'to', 'at', 'by', 'on', 'is', 'are', 'was', 'were',
            'has', 'have', 'had', 'be', 'been', 'being',
            'not', 'no', 'nor',
            'i', 'you', 'he', 'she', 'it', 'we', 'they',
            'my', 'your', 'his', 'her', 'its', 'our', 'their',
            'this', 'that', 'these', 'those',
            'some', 'any', 'all', 'each', 'every',
            'very', 'just', 'also', 'so', 'too',
            'into', 'about', 'than', 'then', 'there', 'here', 'where', 'when',
            'what', 'which', 'who', 'how', 'can', 'could', 'would', 'should',
            'will', 'shall', 'may', 'might', 'must', 'need',
            'more', 'most', 'much', 'many', 'few', 'less',
            'only', 'own', 'other', 'another', 'such',
            'like', 'get', 'got', 'make', 'made',
        );
    }

    // =========================================================================
    // STEMMING (Phase 16.2)
    // =========================================================================

    /**
     * Lightweight German stemmer: removes common adjective and plural suffixes.
     * Only stems if result has at least 3 characters.
     * 
     * @param string $token Lowercase token
     * @return string Stemmed token (or original if no suffix matched)
     */
    private static function stem_token($token) {
        // ONLY safe German adjective/plural endings
        // Order: longest first, but ONLY endings that don't destroy word stems
        $suffixes = array('isches', 'ische', 'ischer', 'ischen', 'ischem', 'iges', 'iger', 'igem', 'igen', 'ige', 'em', 'en', 'er', 'es', 'e');

        foreach ($suffixes as $suffix) {
            $len = mb_strlen($suffix);
            $remaining = mb_strlen($token) - $len;
            // For single-char suffix "e": allow 3-char stems (rot, neu, alt, blau, grün...)
            // For all others: keep minimum of 4
            $min_remaining = ($suffix === 'e') ? 3 : 4;
            if ($remaining >= $min_remaining && mb_substr($token, -$len) === $suffix) {
                $stemmed = mb_substr($token, 0, -$len);
                // Safety: don't stem if result would be a single repeated character
                if (mb_strlen(count_chars($stemmed, 3)) > 1 || mb_strlen($stemmed) > 2) {
                    return $stemmed;
                }
            }
        }
        return $token;
    }

    /**
     * Check if a token is a known brand in the database.
     * Results are cached for the duration of the request.
     * 
     * @param string $token Lowercase token
     * @return bool True if token matches a brand name
     */
    private static function is_known_brand($token) {
        global $wpdb;

        if (self::$known_brands_cache === null) {
            self::$known_brands_cache = array();
            if (class_exists('MyFeeds_DB_Manager') && MyFeeds_DB_Manager::table_exists()) {
                $table = MyFeeds_DB_Manager::table_name();
                $brands = $wpdb->get_col("SELECT DISTINCT LOWER(brand) FROM {$table} WHERE brand IS NOT NULL AND brand != '' LIMIT 5000");
                if (!empty($brands)) {
                    self::$known_brands_cache = array_flip($brands);
                }
            }
        }

        return isset(self::$known_brands_cache[$token]);
    }

    // =========================================================================
    // UMLAUT NORMALIZATION (Phase 16.2)
    // =========================================================================

    /**
     * Normalize German umlauts to ASCII equivalents.
     * ä→ae, ö→oe, ü→ue, ß→ss
     * 
     * @param string $token Token to normalize
     * @return string Normalized token
     */
    private static function normalize_umlauts($token) {
        $map = array('ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss');
        return strtr($token, $map);
    }

    /**
     * Reverse umlaut normalization: ae→ä, oe→ö, ue→ü, ss→ß
     * 
     * @param string $token Token to denormalize
     * @return string Denormalized token (or original if no match)
     */
    private static function denormalize_umlauts($token) {
        $map = array('ae' => 'ä', 'oe' => 'ö', 'ue' => 'ü', 'ss' => 'ß');
        return strtr($token, $map);
    }

    // =========================================================================
    // PHASE A: Query Expansion (Phase 16.2 rewrite)
    // =========================================================================

    /**
     * Expand search query: stop words → stemming → umlaut normalization → synonyms.
     * 
     * @param string $query Raw search query
     * @return array Expansion result
     */
    public static function expand_query($query) {
        $query = mb_strtolower(trim($query));
        $raw_tokens = array_values(array_filter(preg_split('/\s+/', $query)));

        if (empty($raw_tokens)) {
            return array(
                'original_tokens' => array(),
                'expanded_tokens' => array(),
                'synonym_map' => array(),
                'search_for_male' => false,
                'search_for_female' => false,
            );
        }

        // Step 1: Remove stop words
        $stop_words = array_flip(self::get_stop_words());
        $tokens_clean = array();
        foreach ($raw_tokens as $t) {
            if (!isset($stop_words[$t])) {
                $tokens_clean[] = $t;
            }
        }

        // Safety: if all tokens were stop words, use originals
        if (empty($tokens_clean)) {
            $tokens_clean = $raw_tokens;
        }

        $synonyms = self::get_synonyms();
        $male_terms = self::get_male_terms();
        $female_terms = self::get_female_terms();

        $original_tokens = $tokens_clean; // These are the "search keywords" after stop word removal
        $expanded_tokens = array();
        $synonym_map = array(); // token => array of synonyms (for scoring)
        $search_for_male = false;
        $search_for_female = false;

        foreach ($original_tokens as $token) {
            // Always include the original token
            $expanded_tokens[] = $token;

            // Detect gender intent (before stemming)
            if (in_array($token, $male_terms, true)) {
                $search_for_male = true;
            }
            if (in_array($token, $female_terms, true)) {
                $search_for_female = true;
            }

            // Collect all variant forms of this token for synonym lookup
            $lookup_forms = array($token);
            $all_syns_for_token = array();

            // Step 2: Stemming (skip for known brands)
            $is_brand = self::is_known_brand($token);
            $stemmed = $token;
            if (!$is_brand) {
                $stemmed = self::stem_token($token);
                if ($stemmed !== $token) {
                    $lookup_forms[] = $stemmed;
                    $expanded_tokens[] = $stemmed;
                }
            }

            // Step 3: Umlaut normalization (for original AND stemmed)
            foreach (array($token, $stemmed) as $form) {
                $normalized = self::normalize_umlauts($form);
                if ($normalized !== $form && !in_array($normalized, $expanded_tokens, true)) {
                    $expanded_tokens[] = $normalized;
                    $lookup_forms[] = $normalized;
                }
                // Reverse: if user typed "gruen", also try "grün"
                $denormalized = self::denormalize_umlauts($form);
                if ($denormalized !== $form && !in_array($denormalized, $expanded_tokens, true)) {
                    $expanded_tokens[] = $denormalized;
                    $lookup_forms[] = $denormalized;
                }
            }

            // Step 4: Synonym lookup on all forms
            foreach ($lookup_forms as $form) {
                if (isset($synonyms[$form])) {
                    foreach ($synonyms[$form] as $syn) {
                        if (!in_array($syn, $all_syns_for_token, true) && $syn !== $token) {
                            $all_syns_for_token[] = $syn;
                        }
                        if (!in_array($syn, $expanded_tokens, true)) {
                            $expanded_tokens[] = $syn;
                        }
                    }
                }
            }

            if (!empty($all_syns_for_token)) {
                $synonym_map[$token] = $all_syns_for_token;
            }
        }

        return array(
            'original_tokens' => $original_tokens,
            'expanded_tokens' => array_values(array_unique($expanded_tokens)),
            'synonym_map' => $synonym_map,
            'search_for_male' => $search_for_male,
            'search_for_female' => $search_for_female,
        );
    }

    // =========================================================================
    // PHASE B: FULLTEXT Query Builder
    // =========================================================================

    /**
     * Build a MySQL BOOLEAN MODE FULLTEXT query string.
     * Groups each original token with its synonyms/stems/variants.
     * Short tokens (< 4 chars) are collected for LIKE fallback.
     * Gender tokens are excluded from the FULLTEXT query (handled by PHP filter).
     * 
     * @param array $original_tokens Original search tokens (after stop word removal)
     * @param array $synonym_map Token => synonyms mapping
     * @param array $gender_tokens Tokens identified as gender terms to exclude
     * @return array ['fulltext_query' => string, 'short_tokens' => array]
     */
    private static function build_fulltext_query($original_tokens, $synonym_map, $gender_tokens = array()) {
        $groups = array();
        $short_tokens = array();

        // Build set of gender tokens for fast lookup
        $gender_set = array_flip($gender_tokens);

        // Determine non-gender tokens
        $non_gender_tokens = array();
        foreach ($original_tokens as $token) {
            if (!isset($gender_set[$token])) {
                $non_gender_tokens[] = $token;
            }
        }

        // If ALL tokens are gender terms, include them anyway (don't produce empty query)
        $tokens_for_ft = !empty($non_gender_tokens) ? $non_gender_tokens : $original_tokens;

        foreach ($tokens_for_ft as $token) {
            $clean_token = self::sanitize_fulltext_token($token);
            if (empty($clean_token)) {
                continue;
            }

            // Collect short tokens for LIKE fallback (< 4 chars)
            if (mb_strlen($clean_token) < 4) {
                $short_tokens[] = $token;
            }

            // Also add stemmed form
            $stemmed = self::stem_token($token);
            $clean_stemmed = ($stemmed !== $token) ? self::sanitize_fulltext_token($stemmed) : '';

            if (isset($synonym_map[$token]) && !empty($synonym_map[$token])) {
                $group_parts = array($clean_token);
                if (!empty($clean_stemmed) && !in_array($clean_stemmed, $group_parts, true)) {
                    $group_parts[] = $clean_stemmed;
                }
                foreach ($synonym_map[$token] as $syn) {
                    $clean_syn = self::sanitize_fulltext_token($syn);
                    if (!empty($clean_syn) && !in_array($clean_syn, $group_parts, true)) {
                        $group_parts[] = $clean_syn;
                        if (mb_strlen($clean_syn) < 4) {
                            $short_tokens[] = $syn;
                        }
                    }
                }
                $groups[] = '+(' . implode(' ', $group_parts) . ')';
            } else {
                // No synonyms — token + optional stem
                if (!empty($clean_stemmed) && $clean_stemmed !== $clean_token) {
                    $groups[] = '+(' . $clean_token . ' ' . $clean_stemmed . ')';
                } else {
                    $groups[] = '+' . $clean_token;
                }
            }
        }

        return array(
            'fulltext_query' => implode(' ', $groups),
            'short_tokens' => array_values(array_unique($short_tokens)),
        );
    }

    private static function sanitize_fulltext_token($token) {
        $clean = preg_replace('/[+\-><()~*"@]/', '', $token);
        return trim($clean);
    }

    // =========================================================================
    // PHASE C: Gender Filter (unchanged from 16.1)
    // =========================================================================

    private static function apply_gender_filter($rows, $search_for_male, $search_for_female) {
        if ((!$search_for_male && !$search_for_female) || ($search_for_male && $search_for_female)) {
            return $rows;
        }

        $male_terms = self::get_male_terms();
        $female_terms = self::get_female_terms();
        $filtered = array();

        foreach ($rows as $row) {
            $text = mb_strtolower($row['search_text'] ?? ($row['product_name'] . ' ' . ($row['brand'] ?? '') . ' ' . ($row['category'] ?? '')));

            if ($search_for_male && !$search_for_female) {
                $has_female = false;
                $has_male = false;
                foreach ($female_terms as $ft) {
                    if (preg_match('/\b' . preg_quote($ft, '/') . '\b/iu', $text)) {
                        $has_female = true;
                        break;
                    }
                }
                foreach ($male_terms as $mt) {
                    if (preg_match('/\b' . preg_quote($mt, '/') . '\b/iu', $text)) {
                        $has_male = true;
                        break;
                    }
                }
                if ($has_female && !$has_male) {
                    continue;
                }
            }

            if ($search_for_female && !$search_for_male) {
                $has_female = false;
                $has_male = false;
                foreach ($male_terms as $mt) {
                    if (preg_match('/\b' . preg_quote($mt, '/') . '\b/iu', $text)) {
                        $has_male = true;
                        break;
                    }
                }
                foreach ($female_terms as $ft) {
                    if (preg_match('/\b' . preg_quote($ft, '/') . '\b/iu', $text)) {
                        $has_female = true;
                        break;
                    }
                }
                if ($has_male && !$has_female) {
                    continue;
                }
            }

            $filtered[] = $row;
        }

        return $filtered;
    }

    // =========================================================================
    // PHASE D: 3-Tier Weighted Scoring (Phase 16.2 rewrite)
    // =========================================================================

    /**
     * Calculate relevance score using 3-tier system.
     * 
     * Tier 1 (1000+): ALL keywords matched → full match
     * Tier 2 (100-999): >= 50% keywords matched → partial match
     * Tier 3 (10-99): < 50% keywords matched → weak match
     * 
     * @param array $row DB row
     * @param array $scoring_tokens Search keywords WITHOUT gender tokens
     * @param array $synonym_map Token => synonyms
     * @param array $gender_tokens Gender tokens for bonus calculation
     * @param bool $search_for_female User searches for female
     * @param bool $search_for_male User searches for male
     * @return int Relevance score
     */
    /**
     * Word-boundary-aware matching score.
     * Returns 1.0 for exact word match, 0.3 for substring match, 0.0 for no match.
     *
     * @param string $haystack Text to search in (already lowercased)
     * @param string $needle   Token to find (already lowercased)
     * @return float 1.0|0.3|0.0
     */
    private static function word_match_score($haystack, $needle) {
        if (empty($haystack) || empty($needle)) {
            return 0.0;
        }
        // Exact word boundary match: full points
        if (preg_match('/\b' . preg_quote($needle, '/') . '\b/iu', $haystack)) {
            return 1.0;
        }
        // Substring match: reduced points
        if (mb_strpos($haystack, $needle) !== false) {
            return 0.3;
        }
        return 0.0;
    }

    private static function calculate_score($row, $scoring_tokens, $synonym_map, $gender_tokens = array(), $search_for_female = false, $search_for_male = false) {
        $product_name = mb_strtolower($row['product_name'] ?? '');
        $brand = mb_strtolower($row['brand'] ?? '');
        $colour = mb_strtolower($row['colour'] ?? '');
        $category = mb_strtolower($row['category'] ?? '');
        $feed_name = mb_strtolower($row['feed_name'] ?? '');

        $total_keywords = count($scoring_tokens);
        if ($total_keywords === 0) {
            return 0;
        }

        $keywords_found = 0;
        $field_score = 0;
        $all_in_name = true;

        foreach ($scoring_tokens as $token) {
            $token_lower = mb_strtolower($token);
            $stemmed = self::stem_token($token_lower);
            $found = false;

            // Build list of forms to check: original, stemmed, umlaut-normalized
            $check_forms = array($token_lower);
            if ($stemmed !== $token_lower) {
                $check_forms[] = $stemmed;
            }
            $norm = self::normalize_umlauts($token_lower);
            if ($norm !== $token_lower) {
                $check_forms[] = $norm;
            }

            // Check original/stem forms in each field (word-boundary-aware)
            foreach ($check_forms as $form) {
                $wm = self::word_match_score($product_name, $form);
                if ($wm > 0) {
                    $field_score += round(10 * $wm);
                    $found = true;
                }
                $wm = self::word_match_score($brand, $form);
                if ($wm > 0) {
                    $field_score += round(5 * $wm);
                    $found = true;
                }
                $wm = self::word_match_score($colour, $form);
                if ($wm > 0) {
                    $field_score += round(3 * $wm);
                    $found = true;
                }
                $wm = self::word_match_score($category, $form);
                if ($wm > 0) {
                    $field_score += round(2 * $wm);
                    $found = true;
                }
                $wm = self::word_match_score($feed_name, $form);
                if ($wm > 0) {
                    $field_score += round(2 * $wm);
                    $found = true;
                }
                if ($found) {
                    break; // Found via this form, no need to check more forms
                }
            }

            // If not found via original/stem, check synonyms (half points, word-boundary-aware)
            if (!$found && isset($synonym_map[$token])) {
                foreach ($synonym_map[$token] as $syn) {
                    $syn_lower = mb_strtolower($syn);
                    $wm = self::word_match_score($product_name, $syn_lower);
                    if ($wm > 0) {
                        $field_score += round(5 * $wm);
                        $found = true;
                        break;
                    }
                    $wm = self::word_match_score($brand, $syn_lower);
                    if ($wm > 0) {
                        $field_score += round(3 * $wm);
                        $found = true;
                        break;
                    }
                    $wm = self::word_match_score($colour, $syn_lower);
                    if ($wm > 0) {
                        $field_score += round(2 * $wm);
                        $found = true;
                        break;
                    }
                    $wm_cat = self::word_match_score($category, $syn_lower);
                    $wm_feed = self::word_match_score($feed_name, $syn_lower);
                    if ($wm_cat > 0 || $wm_feed > 0) {
                        $field_score += max(1, round(1 * max($wm_cat, $wm_feed)));
                        $found = true;
                        break;
                    }
                }
            }

            if ($found) {
                $keywords_found++;
            }

            // Track if ALL tokens are in product_name (word-boundary-aware)
            $in_name = false;
            foreach ($check_forms as $form) {
                if (self::word_match_score($product_name, $form) > 0) {
                    $in_name = true;
                    break;
                }
            }
            if (!$in_name && isset($synonym_map[$token])) {
                foreach ($synonym_map[$token] as $syn) {
                    if (self::word_match_score($product_name, mb_strtolower($syn)) > 0) {
                        $in_name = true;
                        break;
                    }
                }
            }
            if (!$in_name) {
                $all_in_name = false;
            }
        }

        // 3-Tier base score (strict separation)
        $match_ratio = $keywords_found / $total_keywords;
        if ($match_ratio == 1.0) {
            $base_score = 10000;  // Full match: far above everything else
        } elseif ($match_ratio >= 0.75) {
            $base_score = 500;    // Nearly complete (e.g. 3 of 4 keywords)
        } elseif ($match_ratio >= 0.5) {
            $base_score = 100;    // Half match
        } else {
            $base_score = 10;     // Weak match
        }

        $score = $base_score + $field_score;

        // Bonus: ALL keywords found in product_name
        if ($all_in_name && $total_keywords > 1) {
            $score += 50;
        }

        // Bonus: Brand exactly matches a token
        if (!empty($brand)) {
            foreach ($scoring_tokens as $token) {
                if (preg_match('/\b' . preg_quote(mb_strtolower($token), '/') . '\b/iu', $brand)) {
                    $score += 20;
                    break;
                }
            }
        }

        // Gender bonus: product has matching gender in name/search_text → +500
        if (!empty($gender_tokens)) {
            $product_text = $product_name . ' ' . mb_strtolower($row['search_text'] ?? '');
            $female_terms = self::get_female_terms();
            $male_terms = self::get_male_terms();

            if ($search_for_female) {
                foreach ($female_terms as $ft) {
                    if (mb_strpos($product_text, $ft) !== false) {
                        $score += 500;
                        break;
                    }
                }
            }
            if ($search_for_male) {
                foreach ($male_terms as $mt) {
                    if (mb_strpos($product_text, $mt) !== false) {
                        $score += 500;
                        break;
                    }
                }
            }
        }

        return $score;
    }

    // =========================================================================
    // PHASE E: Deduplication (unchanged from 16.1)
    // =========================================================================

    /**
     * Strip size suffixes from product names for deduplication.
     * "Denim Tears University Shorts Grey - S" → "Denim Tears University Shorts Grey"
     */
    private static function strip_size_suffix($name) {
        // Strip " - SIZE" patterns (XS, S, M, L, XL, XXL, XXXL, EU/US/UK numbers, bare numbers)
        $cleaned = preg_replace('/\s*[-–]\s*(XXXL|XXL|XL|XS|S|M|L|EU\s*\d+|US\s*\d+|UK\s*\d+|\d{2,3})\s*$/i', '', $name);

        // Strip product/color codes at end like "C100", "C201", "F302"
        $cleaned = preg_replace('/\s+[A-Z]\d{2,4}\s*$/i', '', $cleaned);

        return trim($cleaned);
    }

    private static function deduplicate($scored_rows) {
        $seen = array();
        $result = array();

        foreach ($scored_rows as $item) {
            $row = $item['row'];
            $name = mb_strtolower(self::strip_size_suffix($row['product_name'] ?? ''));
            $colour = mb_strtolower(trim($row['colour'] ?? ''));

            if (!empty($colour)) {
                $dedup_key = $name . '|' . $colour;
            } else {
                $dedup_key = $name . '|__no_colour__';
            }

            if (isset($seen[$dedup_key])) {
                continue;
            }
            $seen[$dedup_key] = true;
            $result[] = $item;
        }

        return $result;
    }

    // =========================================================================
    // MAIN SEARCH METHOD
    // =========================================================================

    public static function search($query, $limit = 50, $offset = 0) {
        global $wpdb;
        $table = MyFeeds_DB_Manager::table_name();

        $query = trim($query);
        if (empty($query)) {
            return array();
        }

        // =====================================================================
        // PHASE A: Expand query (stop words → stemming → umlauts → synonyms)
        // =====================================================================
        $expansion = self::expand_query($query);
        $original_tokens = $expansion['original_tokens'];
        $synonym_map = $expansion['synonym_map'];

        if (empty($original_tokens)) {
            return array();
        }

        myfeeds_log("SEARCH: query='{$query}', tokens=[" . implode(', ', $original_tokens) . "], expanded=[" . implode(', ', array_slice($expansion['expanded_tokens'], 0, 15)) . "], male=" . ($expansion['search_for_male'] ? 'Y' : 'N') . ", female=" . ($expansion['search_for_female'] ? 'Y' : 'N'), 'debug');

        // =====================================================================
        // PHASE B: Build FULLTEXT query + LIKE fallback for short tokens
        // =====================================================================
        // Gender tokens have 3 DIFFERENT roles:
        //   FULLTEXT query:  EXCLUDED (so products without gender in name are found)
        //   match_ratio/Tier: EXCLUDED (gender is not a product keyword)
        //   Score BONUS:     +500 if product has matching gender in name
        //   Gender filter:   Used as EXCLUSION filter (remove opposite gender)
        $gender_tokens = array();
        $scoring_tokens = array(); // Without gender → for match_ratio in calculate_score()

        foreach ($original_tokens as $token) {
            if (self::is_gender_token($token)) {
                $gender_tokens[] = $token;
            } else {
                $scoring_tokens[] = $token;
            }
        }

        // Safety: if ALL tokens are gender (e.g. search for "women"), use them as keywords
        if (empty($scoring_tokens) && !empty($gender_tokens)) {
            $scoring_tokens = $gender_tokens;
            $gender_tokens = array(); // No bonus needed when it's the only keyword
        }

        // FULLTEXT query excludes gender tokens (handled internally by build_fulltext_query)
        $ft_data = self::build_fulltext_query($original_tokens, $synonym_map, $gender_tokens);
        $ft_query_str = $ft_data['fulltext_query'];
        $short_tokens = $ft_data['short_tokens'];

        $fetch_limit = max($limit * 4, 200);
        $rows = array();

        $has_search_text = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'search_text'");

        if ($has_search_text && !empty($ft_query_str)) {
            if (!empty($short_tokens)) {
                // LIKE conditions for short tokens (< 4 chars, not indexed by FULLTEXT)
                $like_conditions = array();
                $like_values = array();
                foreach ($short_tokens as $st) {
                    if (is_numeric($st)) {
                        // Numeric tokens: use REGEXP word boundary to avoid "1" matching "19149"
                        $like_conditions[] = '(search_text REGEXP %s)';
                        $like_values[] = '[[:<:]]' . $st . '[[:>:]]';
                    } else {
                        $like_conditions[] = 'search_text LIKE %s';
                        $like_values[] = '%' . $wpdb->esc_like($st) . '%';
                    }
                }
                $like_sql = implode(' AND ', $like_conditions);

                $sql = "SELECT * FROM {$table}
                        WHERE status = 'active'
                        AND (
                            MATCH(search_text) AGAINST(%s IN BOOLEAN MODE)
                            OR ({$like_sql})
                        )
                        LIMIT %d";

                $params = array_merge(array($ft_query_str), $like_values, array($fetch_limit));
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic FULLTEXT search query, user tokens sanitized individually via $wpdb->prepare()
                $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
            } else {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$table}
                     WHERE status = 'active'
                     AND MATCH(search_text) AGAINST(%s IN BOOLEAN MODE)
                     LIMIT %d",
                    $ft_query_str, $fetch_limit
                ), ARRAY_A);
            }

            if (empty($rows)) {
                myfeeds_log("SEARCH: FULLTEXT returned 0 results, falling back to LIKE", 'debug');
                $rows = self::like_fallback_search($table, $original_tokens, $synonym_map, $gender_tokens, $fetch_limit);
            }
        } else {
            myfeeds_log("SEARCH: No search_text column/FULLTEXT index, using LIKE fallback", 'debug');
            $rows = self::like_fallback_search($table, $original_tokens, $synonym_map, $gender_tokens, $fetch_limit);
        }

        $fulltext_count = count($rows);
        myfeeds_log("SEARCH: Candidates={$fulltext_count}", 'debug');

        if (empty($rows)) {
            myfeeds_log("SEARCH: No results for '{$query}'", 'debug');
            return array();
        }

        // =====================================================================
        // PHASE C: Gender filter
        // =====================================================================
        $rows_before_gender = $rows;
        $rows = self::apply_gender_filter($rows, $expansion['search_for_male'], $expansion['search_for_female']);
        $gender_count = count($rows);

        // Fallback: if gender filter removed ALL results, use unfiltered
        if (empty($rows) && !empty($rows_before_gender)) {
            myfeeds_log("SEARCH: Gender filter removed all results, falling back to unfiltered", 'debug');
            $rows = $rows_before_gender;
            $gender_count = count($rows);
        }

        myfeeds_log("SEARCH: After gender filter={$gender_count}", 'debug');

        // =====================================================================
        // PHASE D: 3-Tier weighted scoring (scoring_tokens WITHOUT gender for match_ratio)
        // =====================================================================
        $scored_rows = array();
        foreach ($rows as $row) {
            $score = self::calculate_score($row, $scoring_tokens, $synonym_map, $gender_tokens, $expansion['search_for_female'], $expansion['search_for_male']);
            $scored_rows[] = array('row' => $row, 'score' => $score);
        }

        usort($scored_rows, function ($a, $b) {
            return $b['score'] - $a['score'];
        });

        // =====================================================================
        // Tier cutoff: if enough full matches exist, drop partial matches
        // =====================================================================
        $tier1_count = 0;
        foreach ($scored_rows as $item) {
            if ($item['score'] >= 10000) {
                $tier1_count++;
            }
        }

        if ($tier1_count >= 10) {
            $scored_rows = array_values(array_filter($scored_rows, function($item) {
                return $item['score'] >= 10000;
            }));
            myfeeds_log("SEARCH: Tier cutoff applied — {$tier1_count} Tier-1 results, dropped partial matches", 'debug');
        }

        // =====================================================================
        // PHASE E: Deduplication
        // =====================================================================
        $scored_rows = self::deduplicate($scored_rows);
        $dedup_count = count($scored_rows);
        myfeeds_log("SEARCH: After dedup={$dedup_count}", 'debug');

        $scored_rows = array_slice($scored_rows, $offset, $limit);

        // Log top 3 results with tier info
        $top3 = array_slice($scored_rows, 0, 3);
        $top3_log = array();
        foreach ($top3 as $item) {
            $tier = $item['score'] >= 10000 ? 'T1' : ($item['score'] >= 500 ? 'T2' : ($item['score'] >= 100 ? 'T3' : 'T4'));
            $top3_log[] = mb_substr($item['row']['product_name'] ?? '', 0, 40) . " ({$tier}:score={$item['score']})";
        }
        myfeeds_log("SEARCH: Top results: [" . implode(', ', $top3_log) . "]", 'debug');

        // =====================================================================
        // Convert to product format
        // =====================================================================
        $products = array();
        foreach ($scored_rows as $item) {
            $product = self::row_to_product_simple($item['row']);
            $products[$product['id']] = $product;
        }

        return $products;
    }

    /**
     * LIKE-based fallback search.
     * AND between original token groups, OR within each group (original + synonyms).
     * Example: "backpack schwarz" → (search_text LIKE '%backpack%' OR LIKE '%rucksack%') AND (LIKE '%schwarz%' OR LIKE '%black%')
     * 
     * @param string $table Table name
     * @param array $original_tokens Original search tokens
     * @param array $synonym_map Token => synonyms mapping
     * @param array $gender_tokens Gender tokens to exclude from query
     * @param int $limit Max rows
     * @return array DB rows
     */
    private static function like_fallback_search($table, $original_tokens, $synonym_map, $gender_tokens, $limit) {
        global $wpdb;

        if (empty($original_tokens)) {
            return array();
        }

        // Build set of gender tokens to exclude
        $gender_set = array_flip($gender_tokens);
        $non_gender_tokens = array();
        foreach ($original_tokens as $token) {
            if (!isset($gender_set[$token])) {
                $non_gender_tokens[] = $token;
            }
        }

        // If all tokens are gender, use them anyway
        $tokens_for_like = !empty($non_gender_tokens) ? $non_gender_tokens : $original_tokens;

        $and_groups = array();
        $all_values = array();

        foreach ($tokens_for_like as $token) {
            // Build OR group for this token: original + stem + synonyms
            $or_parts = array();
            $forms = array($token);

            // Add stemmed form
            $stemmed = self::stem_token($token);
            if ($stemmed !== $token) {
                $forms[] = $stemmed;
            }

            // Add umlaut variants
            $norm = self::normalize_umlauts($token);
            if ($norm !== $token) {
                $forms[] = $norm;
            }
            $denorm = self::denormalize_umlauts($token);
            if ($denorm !== $token) {
                $forms[] = $denorm;
            }

            // Add synonyms
            if (isset($synonym_map[$token])) {
                foreach ($synonym_map[$token] as $syn) {
                    $forms[] = $syn;
                }
            }

            $forms = array_unique($forms);

            foreach ($forms as $form) {
                if (is_numeric($form)) {
                    // Numeric tokens: REGEXP word boundary to avoid partial number matches
                    $or_parts[] = '(search_text REGEXP %s)';
                    $all_values[] = '[[:<:]]' . $form . '[[:>:]]';
                } else {
                    $like = '%' . $wpdb->esc_like($form) . '%';
                    $or_parts[] = 'search_text LIKE %s';
                    $all_values[] = $like;
                }
            }

            if (!empty($or_parts)) {
                $and_groups[] = '(' . implode(' OR ', $or_parts) . ')';
            }
        }

        if (empty($and_groups)) {
            return array();
        }

        $where = implode(' AND ', $and_groups);
        $all_values[] = $limit;

        // $table is built from $wpdb->prefix + a constant string. $where is
        // assembled from constant SQL fragments ('search_text LIKE %s' etc.);
        // every dynamic value is bound through $all_values via prepare().
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = 'active' AND {$where} LIMIT %d",
            ...$all_values
        ), ARRAY_A);

        return $rows ?: array();
    }

    /**
     * Minimal row-to-product conversion.
     * Mirrors MyFeeds_DB_Manager::row_to_product() output format.
     */
    private static function row_to_product_simple($row) {
        $product = array(
            'id'                  => $row['external_id'],
            'title'               => $row['product_name'] ?? '',
            'price'               => floatval($row['price'] ?? 0),
            'old_price'           => floatval($row['original_price'] ?? 0),
            'currency'            => $row['currency'] ?? 'EUR',
            'image_url'           => $row['image_url'] ?? '',
            'affiliate_link'      => $row['affiliate_link'] ?? '',
            'brand'               => $row['brand'] ?? '',
            'category'            => $row['category'] ?? '',
            'colour'              => $row['colour'] ?? '',
            'in_stock'            => (int) ($row['in_stock'] ?? 1),
            'status'              => $row['status'] ?? 'active',
            'merchant'            => $row['feed_name'] ?? '',
            'last_updated'        => $row['last_updated'] ?? '',
        );

        if (!empty($row['raw_data'])) {
            $raw = json_decode($row['raw_data'], true);
            if (is_array($raw)) {
                $product = array_merge($raw, $product);
            }
        }

        return $product;
    }
}
