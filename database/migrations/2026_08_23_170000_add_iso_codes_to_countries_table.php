<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ISO 3166-1 identity for each country, so a world map can be joined to our
     * rows. The `code` column carries olympic-style codes inherited from the
     * legacy app (SRB, CRO, MON — MON being Montenegro here, not Monaco), which
     * no map dataset understands.
     *
     * Both forms are stored because map datasets disagree: SVG maps key on
     * alpha-2, TopoJSON world atlases on the numeric code.
     */
    private const ISO = [
        // legacy code => [alpha-2, numeric]
        'SRB' => ['RS', 688], 'NMK' => ['MK', 807], 'EGY' => ['EG', 818], 'ITA' => ['IT', 380],
        'SLO' => ['SI', 705], 'CRO' => ['HR', 191], 'BIH' => ['BA', 70], 'MON' => ['ME', 499],
        'ALB' => ['AL', 8], 'BLG' => ['BG', 100], 'UKR' => ['UA', 804], 'ROM' => ['RO', 642],
        'GER' => ['DE', 276], 'BRA' => ['BR', 76], 'MOZ' => ['MZ', 508], 'LAT' => ['LV', 428],
        'CHN' => ['CN', 156], 'RSA' => ['ZA', 710], 'MAL' => ['MY', 458], 'RUS' => ['RU', 643],
        'CYP' => ['CY', 196], 'MNG' => ['MN', 496], 'GRC' => ['GR', 300], 'HUN' => ['HU', 348],
        'UZB' => ['UZ', 860], 'IND' => ['IN', 356], 'TUR' => ['TR', 792], 'VNM' => ['VN', 704],
        'PAK' => ['PK', 586], 'PHL' => ['PH', 608], 'TJT' => ['TJ', 762], 'DZA' => ['DZ', 12],
        'ESP' => ['ES', 724], 'KHM' => ['KH', 116], 'BRN' => ['BN', 96], 'GEO' => ['GE', 268],
        'IDN' => ['ID', 360], 'KGZ' => ['KG', 417], 'LAO' => ['LA', 418], 'MMR' => ['MM', 104],
        'SGP' => ['SG', 702], 'MWI' => ['MW', 454], 'THA' => ['TH', 764], 'EST' => ['EE', 233],
        'IRQ' => ['IQ', 368], 'LBN' => ['LB', 422], 'AM' => ['AM', 51], 'KAZ' => ['KZ', 398],
        'MDA' => ['MD', 498], 'POL' => ['PL', 616], 'TM' => ['TM', 795], 'IRN' => ['IR', 364],
        'KOR' => ['KR', 410], 'SVK' => ['SK', 703], 'SAU' => ['SA', 682], 'LTU' => ['LT', 440],
        'AZE' => ['AZ', 31], 'PSE' => ['PS', 275], 'AFG' => ['AF', 4], 'JPN' => ['JP', 392],
        'TZA' => ['TZ', 834], 'HKG' => ['HK', 344], 'MAC' => ['MO', 446], 'TWN' => ['TW', 158],
        'TUN' => ['TN', 788], 'UAE' => ['AE', 784], 'MAR' => ['MA', 504], 'AUT' => ['AT', 40],
        'CZE' => ['CZ', 203], 'JOR' => ['JO', 400], 'GIN' => ['GN', 324], 'KWT' => ['KW', 414],
        'GHA' => ['GH', 288], 'BGD' => ['BD', 50], 'NRA' => ['NG', 566], 'QAT' => ['QA', 634],
        'MEX' => ['MX', 484], 'TH' => ['TH', 764], 'POR' => ['PT', 620], 'CAN' => ['CA', 124],
        'FRA' => ['FR', 250], 'ISR' => ['IL', 376], 'ZWE' => ['ZW', 716], 'BWA' => ['BW', 72],
        'SDN' => ['SD', 729], 'OMA' => ['OM', 512], 'NER' => ['NE', 562], 'LKA' => ['LK', 144],
        'NPL' => ['NP', 524], 'VEN' => ['VE', 862], 'NZL' => ['NZ', 554], 'BLR' => ['BY', 112],
        'COL' => ['CO', 170], 'ARG' => ['AR', 32],

        // Kosovo has no ISO 3166-1 assignment; XK is the user-assigned code every
        // map dataset uses for it, and there is no numeric counterpart.
        'KOS' => ['XK', null],

        // Countries the dev seeder creates with their own two-letter codes.
        'RS' => ['RS', 688], 'MK' => ['MK', 807], 'EG' => ['EG', 818],

        // 'WRL' (World) is a bucket, not a country: left null and off the map.
    ];

    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->char('iso_alpha2', 2)->nullable()->after('code')->index();
            $table->unsignedSmallInteger('iso_numeric')->nullable()->after('iso_alpha2');
        });

        foreach (self::ISO as $legacy => [$alpha2, $numeric]) {
            DB::table('countries')
                ->where('code', $legacy)
                ->update(['iso_alpha2' => $alpha2, 'iso_numeric' => $numeric]);
        }
    }

    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->dropIndex(['iso_alpha2']);
            $table->dropColumn(['iso_alpha2', 'iso_numeric']);
        });
    }
};
