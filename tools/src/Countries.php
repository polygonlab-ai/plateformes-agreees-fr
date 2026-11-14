<?php

declare(strict_types=1);

namespace PolygonLab\PlateformesAgreees\Outils;

/**
 * Liste fermée des pays étrangers, d'après la norme ISO 3166-1 (noms courts en français), pour reconnaître une
 * plateforme établie hors de France dans la colonne « Commune (Pays le cas échéant) » des fichiers de la DGFiP.
 *
 * Source : ISO 3166-1, noms de pays en français (https://www.iso.org/fr/iso-3166-country-codes.html), sous leur forme
 * courte sans article (« Pays-Bas » pour « Pays-Bas (les) »). Quelques entrées ont aussi leur nom usuel, quand la
 * forme de la norme est longue ou peu employée (« États-Unis », « Royaume-Uni », « Corée du Sud », « République
 * tchèque », « Biélorussie »…) : c'est le même pays, sous un autre nom, jamais un rapprochement approximatif.
 *
 * Ne figurent pas dans la liste : la France, et les territoires français qui ont un code ISO propre mais utilisent
 * les codes postaux français (Guadeloupe, Guyane, Martinique, La Réunion, Mayotte, Nouvelle-Calédonie, Polynésie
 * française, Saint-Barthélemy, Saint-Martin, Saint-Pierre-et-Miquelon, Wallis-et-Futuna, Terres australes et
 * antarctiques françaises) : ils sont traités comme des adresses françaises.
 *
 * Comparaison après normalisation (Columns::normalize) : sans tenir compte des majuscules, des accents, des traits
 * d'union ni de la ponctuation. « Pays Bas », « pays-bas » et « PAYS-BAS » désignent donc les Pays-Bas.
 */
final class Countries
{
    /** Nom écrit pour une adresse française. */
    public const FRANCE = 'France';

    /**
     * Code ISO 3166-1 alpha-2 => noms reconnus (le premier est le nom écrit dans data/).
     *
     * @var array<string, list<string>>
     */
    public const NAMES = [
        'AF' => ['Afghanistan'],
        'ZA' => ['Afrique du Sud'],
        'AX' => ['Îles Åland', 'Åland'],
        'AL' => ['Albanie'],
        'DZ' => ['Algérie'],
        'DE' => ['Allemagne'],
        'AD' => ['Andorre'],
        'AO' => ['Angola'],
        'AI' => ['Anguilla'],
        'AQ' => ['Antarctique'],
        'AG' => ['Antigua-et-Barbuda'],
        'SA' => ['Arabie saoudite'],
        'AR' => ['Argentine'],
        'AM' => ['Arménie'],
        'AW' => ['Aruba'],
        'AU' => ['Australie'],
        'AT' => ['Autriche'],
        'AZ' => ['Azerbaïdjan'],
        'BS' => ['Bahamas'],
        'BH' => ['Bahreïn'],
        'BD' => ['Bangladesh'],
        'BB' => ['Barbade'],
        'BY' => ['Bélarus', 'Biélorussie'],
        'BE' => ['Belgique'],
        'BZ' => ['Belize'],
        'BJ' => ['Bénin'],
        'BM' => ['Bermudes'],
        'BT' => ['Bhoutan'],
        'BO' => ['Bolivie', 'État plurinational de Bolivie'],
        'BQ' => ['Bonaire, Saint-Eustache et Saba'],
        'BA' => ['Bosnie-Herzégovine'],
        'BW' => ['Botswana'],
        'BV' => ['Île Bouvet'],
        'BR' => ['Brésil'],
        'BN' => ['Brunéi Darussalam', 'Brunei'],
        'BG' => ['Bulgarie'],
        'BF' => ['Burkina Faso'],
        'BI' => ['Burundi'],
        'CV' => ['Cabo Verde', 'Cap-Vert'],
        'KY' => ['Îles Caïmans', 'Îles Caïman'],
        'KH' => ['Cambodge'],
        'CM' => ['Cameroun'],
        'CA' => ['Canada'],
        'CF' => ['République centrafricaine', 'Centrafrique'],
        'CL' => ['Chili'],
        'CN' => ['Chine'],
        'CX' => ['Île Christmas'],
        'CY' => ['Chypre'],
        'CC' => ['Îles Cocos', 'Îles Cocos (Keeling)'],
        'CO' => ['Colombie'],
        'KM' => ['Comores'],
        'CG' => ['Congo'],
        'CD' => ['République démocratique du Congo'],
        'CK' => ['Îles Cook'],
        'KR' => ['Corée du Sud', 'République de Corée'],
        'KP' => ['Corée du Nord', 'République populaire démocratique de Corée'],
        'CR' => ['Costa Rica'],
        'CI' => ['Côte d\'Ivoire'],
        'HR' => ['Croatie'],
        'CU' => ['Cuba'],
        'CW' => ['Curaçao'],
        'DK' => ['Danemark'],
        'DJ' => ['Djibouti'],
        'DO' => ['République dominicaine'],
        'DM' => ['Dominique'],
        'EG' => ['Égypte'],
        'SV' => ['El Salvador', 'Salvador'],
        'AE' => ['Émirats arabes unis'],
        'EC' => ['Équateur'],
        'ER' => ['Érythrée'],
        'ES' => ['Espagne'],
        'EE' => ['Estonie'],
        'SZ' => ['Eswatini'],
        'US' => ['États-Unis', 'États-Unis d\'Amérique'],
        'ET' => ['Éthiopie'],
        'FK' => ['Îles Falkland', 'Îles Malouines'],
        'FO' => ['Îles Féroé'],
        'FJ' => ['Fidji'],
        'FI' => ['Finlande'],
        'GA' => ['Gabon'],
        'GM' => ['Gambie'],
        'GE' => ['Géorgie'],
        'GS' => ['Géorgie du Sud-et-les Îles Sandwich du Sud'],
        'GH' => ['Ghana'],
        'GI' => ['Gibraltar'],
        'GR' => ['Grèce'],
        'GD' => ['Grenade'],
        'GL' => ['Groenland'],
        'GU' => ['Guam'],
        'GT' => ['Guatemala'],
        'GG' => ['Guernesey'],
        'GN' => ['Guinée'],
        'GW' => ['Guinée-Bissau'],
        'GQ' => ['Guinée équatoriale'],
        'GY' => ['Guyana'],
        'HT' => ['Haïti'],
        'HM' => ['Îles Heard-et-MacDonald'],
        'HN' => ['Honduras'],
        'HK' => ['Hong Kong'],
        'HU' => ['Hongrie'],
        'IM' => ['Île de Man'],
        'UM' => ['Îles mineures éloignées des États-Unis'],
        'VG' => ['Îles Vierges britanniques'],
        'VI' => ['Îles Vierges des États-Unis'],
        'IN' => ['Inde'],
        'ID' => ['Indonésie'],
        'IR' => ['Iran', 'République islamique d\'Iran'],
        'IQ' => ['Iraq', 'Irak'],
        'IE' => ['Irlande'],
        'IS' => ['Islande'],
        'IL' => ['Israël'],
        'IT' => ['Italie'],
        'JM' => ['Jamaïque'],
        'JP' => ['Japon'],
        'JE' => ['Jersey'],
        'JO' => ['Jordanie'],
        'KZ' => ['Kazakhstan'],
        'KE' => ['Kenya'],
        'KG' => ['Kirghizistan'],
        'KI' => ['Kiribati'],
        'KW' => ['Koweït'],
        'LA' => ['Laos', 'République démocratique populaire lao'],
        'LS' => ['Lesotho'],
        'LV' => ['Lettonie'],
        'LB' => ['Liban'],
        'LR' => ['Libéria'],
        'LY' => ['Libye'],
        'LI' => ['Liechtenstein'],
        'LT' => ['Lituanie'],
        'LU' => ['Luxembourg'],
        'MO' => ['Macao'],
        'MK' => ['Macédoine du Nord'],
        'MG' => ['Madagascar'],
        'MY' => ['Malaisie'],
        'MW' => ['Malawi'],
        'MV' => ['Maldives'],
        'ML' => ['Mali'],
        'MT' => ['Malte'],
        'MP' => ['Îles Mariannes du Nord'],
        'MA' => ['Maroc'],
        'MH' => ['Îles Marshall'],
        'MU' => ['Maurice'],
        'MR' => ['Mauritanie'],
        'MX' => ['Mexique'],
        'FM' => ['Micronésie', 'États fédérés de Micronésie'],
        'MD' => ['Moldavie', 'République de Moldova'],
        'MC' => ['Monaco'],
        'MN' => ['Mongolie'],
        'ME' => ['Monténégro'],
        'MS' => ['Montserrat'],
        'MZ' => ['Mozambique'],
        'MM' => ['Myanmar', 'Birmanie'],
        'NA' => ['Namibie'],
        'NR' => ['Nauru'],
        'NP' => ['Népal'],
        'NI' => ['Nicaragua'],
        'NE' => ['Niger'],
        'NG' => ['Nigéria'],
        'NU' => ['Niue'],
        'NF' => ['Île Norfolk'],
        'NO' => ['Norvège'],
        'NZ' => ['Nouvelle-Zélande'],
        'IO' => ['Territoire britannique de l\'océan Indien'],
        'OM' => ['Oman'],
        'UG' => ['Ouganda'],
        'UZ' => ['Ouzbékistan'],
        'PK' => ['Pakistan'],
        'PW' => ['Palaos'],
        'PS' => ['Palestine', 'État de Palestine'],
        'PA' => ['Panama'],
        'PG' => ['Papouasie-Nouvelle-Guinée'],
        'PY' => ['Paraguay'],
        'NL' => ['Pays-Bas'],
        'PE' => ['Pérou'],
        'PH' => ['Philippines'],
        'PN' => ['Pitcairn'],
        'PL' => ['Pologne'],
        'PR' => ['Porto Rico'],
        'PT' => ['Portugal'],
        'QA' => ['Qatar'],
        'RO' => ['Roumanie'],
        'GB' => ['Royaume-Uni', 'Royaume-Uni de Grande-Bretagne et d\'Irlande du Nord'],
        'RU' => ['Russie', 'Fédération de Russie'],
        'RW' => ['Rwanda'],
        'EH' => ['Sahara occidental'],
        'KN' => ['Saint-Kitts-et-Nevis'],
        'SM' => ['Saint-Marin'],
        'SX' => ['Sint Maarten', 'Saint-Martin (partie néerlandaise)'],
        'VA' => ['Saint-Siège', 'Vatican'],
        'VC' => ['Saint-Vincent-et-les-Grenadines'],
        'SH' => ['Sainte-Hélène, Ascension et Tristan da Cunha'],
        'LC' => ['Sainte-Lucie'],
        'SB' => ['Îles Salomon'],
        'WS' => ['Samoa'],
        'AS' => ['Samoa américaines'],
        'ST' => ['Sao Tomé-et-Principe'],
        'SN' => ['Sénégal'],
        'RS' => ['Serbie'],
        'SC' => ['Seychelles'],
        'SL' => ['Sierra Leone'],
        'SG' => ['Singapour'],
        'SK' => ['Slovaquie'],
        'SI' => ['Slovénie'],
        'SO' => ['Somalie'],
        'SD' => ['Soudan'],
        'SS' => ['Soudan du Sud'],
        'LK' => ['Sri Lanka'],
        'SE' => ['Suède'],
        'CH' => ['Suisse'],
        'SR' => ['Suriname'],
        'SJ' => ['Svalbard et île Jan Mayen'],
        'SY' => ['Syrie', 'République arabe syrienne'],
        'TJ' => ['Tadjikistan'],
        'TW' => ['Taïwan'],
        'TZ' => ['Tanzanie', 'République-Unie de Tanzanie'],
        'TD' => ['Tchad'],
        'CZ' => ['Tchéquie', 'République tchèque'],
        'TH' => ['Thaïlande'],
        'TL' => ['Timor-Leste'],
        'TG' => ['Togo'],
        'TK' => ['Tokelau'],
        'TO' => ['Tonga'],
        'TT' => ['Trinité-et-Tobago'],
        'TN' => ['Tunisie'],
        'TM' => ['Turkménistan'],
        'TC' => ['Îles Turques-et-Caïques'],
        'TR' => ['Turquie', 'Türkiye'],
        'TV' => ['Tuvalu'],
        'UA' => ['Ukraine'],
        'UY' => ['Uruguay'],
        'VU' => ['Vanuatu'],
        'VE' => ['Venezuela', 'République bolivarienne du Venezuela'],
        'VN' => ['Viet Nam', 'Vietnam'],
        'YE' => ['Yémen'],
        'ZM' => ['Zambie'],
        'ZW' => ['Zimbabwe'],
    ];

    /** @var array<string, string>|null nom normalisé => nom écrit */
    private static ?array $index = null;

    /**
     * Pays étranger nommé par la colonne « Commune (Pays le cas échéant) » : la valeur entière, ou le texte entre
     * parenthèses à sa fin (« Espoo (Finlande) »). Null si aucun pays de la liste n'est reconnu (adresse française).
     */
    public static function find(?string $place): ?string
    {
        $place = Text::clean($place);
        if ($place === null) {
            return null;
        }
        $index = self::index();
        $country = $index[Columns::normalize($place)] ?? null;
        if ($country === null && preg_match('/\(([^()]+)\)\z/u', $place, $match) === 1) {
            $country = $index[Columns::normalize($match[1])] ?? null;
        }

        return $country;
    }

    /**
     * @return array<string, string>
     */
    private static function index(): array
    {
        if (self::$index === null) {
            self::$index = [];
            foreach (self::NAMES as $names) {
                foreach ($names as $name) {
                    self::$index[Columns::normalize($name)] = $names[0];
                }
            }
        }

        return self::$index;
    }
}
