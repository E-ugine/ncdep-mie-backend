<?php

namespace Database\Seeders\Mie;

use App\Enums\CommodityCategory;
use App\Enums\ProductFormState;
use App\Models\Commodity;
use App\Models\Country;
use App\Models\Market;
use App\Models\Product;
use App\Models\ProductForm;
use Illuminate\Database\Seeder;

/**
 * Genuinely reference/lookup data: countries, the Hibiscus commodity + its full Fresh/Raw/
 * Processed tree (section 3.9), and markets.
 *
 * Stage 8 issue #1: CountryFactory's `fake()->unique()->country()` only guarantees uniqueness
 * within a single PHP process — a real seeder run more than once (which this constraint requires
 * be safe) can collide. Every row here is created via firstOrCreate() keyed on a natural,
 * stable identifier (iso_code for countries; commodity name; a form's (commodity_id, state)
 * pair; a product's (product_form_id, name) pair; a market's (country_id, name) pair) instead of
 * Factory::create(). Rerunning this seeder is a no-op the second time — nothing here is
 * "variable transactional data," so Factory::create() (meant for numerous, varied rows) was
 * never the right tool for it in the first place.
 */
class ReferenceDataSeeder extends Seeder
{
    /** @var array<string, Country> */
    public array $countries = [];

    public Commodity $hibiscus;

    /** @var array<string, ProductForm> */
    public array $productForms = [];

    /** @var array<string, Product> */
    public array $products = [];

    /** @var array<string, Market> */
    public array $markets = [];

    public function run(): void
    {
        $this->countries['KE'] = Country::firstOrCreate(['iso_code' => 'KEN'], ['name' => 'Kenya']);
        $this->countries['DE'] = Country::firstOrCreate(['iso_code' => 'DEU'], ['name' => 'Germany']);
        $this->countries['EG'] = Country::firstOrCreate(['iso_code' => 'EGY'], ['name' => 'Egypt']);
        $this->countries['NL'] = Country::firstOrCreate(['iso_code' => 'NLD'], ['name' => 'Netherlands']);

        $this->hibiscus = Commodity::firstOrCreate(
            ['name' => 'Hibiscus'],
            [
                'category' => CommodityCategory::Crop,
                'description' => 'Hibiscus sabdariffa, grown for its calyx — used fresh, dried whole, or processed into extract/powder.',
            ],
        );

        $this->productForms['fresh'] = ProductForm::firstOrCreate(
            ['commodity_id' => $this->hibiscus->id, 'state' => ProductFormState::Fresh],
            ['name' => 'Fresh', 'description' => 'Freshly harvested hibiscus flowers/calyces, not yet dried.'],
        );
        $this->productForms['raw'] = ProductForm::firstOrCreate(
            ['commodity_id' => $this->hibiscus->id, 'state' => ProductFormState::Raw],
            ['name' => 'Raw', 'description' => 'Sun-dried, unprocessed whole hibiscus calyx — the standard export form.'],
        );
        $this->productForms['processed'] = ProductForm::firstOrCreate(
            ['commodity_id' => $this->hibiscus->id, 'state' => ProductFormState::Processed],
            ['name' => 'Processed', 'description' => 'Hibiscus extract and powder for food/beverage/cosmetic use.'],
        );

        $this->products['fresh'] = Product::firstOrCreate(
            ['product_form_id' => $this->productForms['fresh']->id, 'name' => 'Fresh Hibiscus Flowers'],
            ['unit_of_measure' => 'kg', 'description' => 'Whole fresh hibiscus flowers, sold for immediate processing.'],
        );
        $this->products['raw'] = Product::firstOrCreate(
            ['product_form_id' => $this->productForms['raw']->id, 'name' => 'Dried Whole Hibiscus Calyx'],
            ['unit_of_measure' => 'MT', 'description' => 'Sun-dried whole hibiscus calyx, bulk export grade for tea/infusion use.'],
        );
        $this->products['processed'] = Product::firstOrCreate(
            ['product_form_id' => $this->productForms['processed']->id, 'name' => 'Hibiscus Extract Powder'],
            ['unit_of_measure' => 'kg', 'description' => 'Concentrated hibiscus extract powder for food, beverage, and cosmetic manufacturers.'],
        );

        $this->markets['de'] = Market::firstOrCreate(
            ['country_id' => $this->countries['DE']->id, 'name' => 'EU Dried Botanicals Import Market'],
            ['description' => 'German/EU import market for dried botanicals and herbal ingredients.'],
        );
        $this->markets['nl'] = Market::firstOrCreate(
            ['country_id' => $this->countries['NL']->id, 'name' => 'Rotterdam Botanicals Distribution Hub'],
            ['description' => 'Netherlands-based distribution hub for botanical/herbal ingredient imports into the EU.'],
        );
    }
}
