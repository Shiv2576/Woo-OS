<?php
/**
 * Mercora catalog seeder — realistic WooCommerce catalog for Alice.
 *
 * Run from Woo-os/:   ./db/seeds/seed.sh [count] [reset]
 *
 * - Deterministic: the same count always produces the same catalog (fixed RNG seed).
 * - Idempotent: products are keyed by SKU (MRC-00001…); re-running skips existing ones.
 * - Every seeded product/variation carries meta _mercora_seed=1 so "reset" removes only seed data.
 * - Edge cases for Alice: out of stock, low stock, backorder, on sale, variable products with
 *   partially sold-out sizes, missing attributes/descriptions/images, near-duplicate products.
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WooCommerce' ) ) {
	fwrite( STDERR, "Run this through wp-cli with WooCommerce active.\n" );
	exit( 1 );
}

$mrc_args  = ( isset( $args ) && is_array( $args ) ) ? $args : [];
$mrc_count = ( isset( $mrc_args[0] ) && is_numeric( $mrc_args[0] ) ) ? max( 1, (int) $mrc_args[0] ) : 500;
$mrc_reset = in_array( 'reset', $mrc_args, true );
$mrc_limit = 0;
foreach ( $mrc_args as $a ) { if ( str_starts_with( (string) $a, "limit=" ) ) { $mrc_limit = (int) substr( $a, 6 ); } }

mt_srand( 42 );

/* ============================================================================
 * Catalog definition
 * ========================================================================== */

$MRC_PALETTE = [
	'Snacks & Candy'         => [ 214, 110, 20 ],
	'Beverages'              => [ 120, 72, 40 ],
	'Clothing'               => [ 64, 86, 160 ],
	'Footwear'               => [ 70, 70, 70 ],
	'Outdoor & Hiking'       => [ 46, 125, 50 ],
	'Electronics'            => [ 30, 34, 56 ],
	'Home & Kitchen'         => [ 160, 108, 64 ],
	'Beauty & Personal Care' => [ 196, 84, 128 ],
	'Books'                  => [ 92, 60, 124 ],
	'Sports & Fitness'       => [ 0, 128, 140 ],
];

$MRC_BLURBS = [
	'Snacks & Candy'         => 'Freshly packed and perfect for snacking at home, at work or on the go.',
	'Beverages'              => 'Sourced carefully and packed to keep every cup and glass tasting fresh.',
	'Clothing'               => 'Designed for everyday comfort with a fit that holds its shape wash after wash.',
	'Footwear'               => 'Built for all-day comfort with durable soles and a supportive fit.',
	'Outdoor & Hiking'       => 'Trail-tested gear made to handle Indian monsoons, mountains and everything between.',
	'Electronics'            => 'Reliable tech backed by a 1-year warranty and easy returns.',
	'Home & Kitchen'         => 'Thoughtfully made to be used every day and last for years.',
	'Beauty & Personal Care' => 'Dermatologically tested, cruelty-free and gentle enough for daily use.',
	'Books'                  => 'A reader favourite, printed on quality paper.',
	'Sports & Fitness'       => 'Made for home workouts and studio sessions alike.',
];

$APPAREL_SIZES = [ 'S', 'M', 'L', 'XL', 'XXL' ];
$SHOE_SIZES    = [ 'UK 6', 'UK 7', 'UK 8', 'UK 9', 'UK 10' ];

$MRC_CATALOG = [
	// ---------------- Snacks & Candy ----------------
	[ 'p' => 'Snacks & Candy', 'n' => 'Sour Candy', 'w' => 1.3, 'price' => [ 49, 349 ],
	  'brands' => [ 'Tangy Town', 'Zingo', 'Candy Karma', 'Pucker Pop' ],
	  'items'  => [ 'Sour Gummy Worms', 'Sour Belts', 'Sour Cola Bottles', 'Sour Watermelon Slices', 'Fizzy Sour Rings', 'Sour Mango Bites', 'Sour Neon Bears', 'Sour Tamarind Chews' ],
	  'mods'   => [ 'Extra Tangy', 'Mixed Fruit', 'Classic', 'Tropical', '' ],
	  'packs'  => [ '100g', '200g', '400g' ],
	  'feats'  => [ 'Tangy sugar-sour coating', 'No artificial colours', 'Resealable pouch', 'Soft and chewy texture', 'Great for sharing', 'Made in India' ],
	  'diet'   => [ 'Vegan' => 0.4, 'Gluten-Free' => 0.3 ], 'tags' => [ 'Party Pack', 'Kids Favourite' ] ],
	[ 'p' => 'Snacks & Candy', 'n' => 'Chocolates', 'w' => 1.2, 'price' => [ 59, 1499 ],
	  'brands' => [ 'Cocoa Craft', 'Velvet Bean', 'Mishti Cacao', 'Nibble & Co' ],
	  'items'  => [ 'Dark Chocolate Bar', 'Milk Chocolate Bar', 'Hazelnut Truffles', 'Salted Caramel Bar', 'Almond Praline Box', 'Orange Dark Bar', 'Chocolate Gift Box', 'Cocoa Nibs' ],
	  'mods'   => [ '70% Dark', 'Sugar-Free', 'Classic', 'Premium', '' ],
	  'packs'  => [ '50g', '100g', '250g' ],
	  'feats'  => [ 'Single-origin cocoa', 'Smooth, rich melt', 'No palm oil', 'Gift-ready packaging', 'Ethically sourced beans' ],
	  'diet'   => [ 'Vegan' => 0.25, 'Sugar-Free' => 0.15, 'Gluten-Free' => 0.3 ], 'tags' => [ 'Gift', 'Festive' ] ],
	[ 'p' => 'Snacks & Candy', 'n' => 'Chips & Namkeen', 'w' => 1.3, 'price' => [ 20, 299 ],
	  'brands' => [ 'Crunch Kitchen', 'Desi Munch', 'Chatpata Co', 'Masala Mill' ],
	  'items'  => [ 'Potato Chips', 'Banana Chips', 'Aloo Bhujia', 'Moong Dal', 'Khakhra', 'Nachos', 'Roasted Makhana', 'Chakli' ],
	  'mods'   => [ 'Masala', 'Salted', 'Peri Peri', 'Cream & Onion', 'Tangy Tomato', 'Pudina' ],
	  'packs'  => [ '50g', '150g', '400g' ],
	  'feats'  => [ 'Crunchy in every bite', 'Cooked in rice bran oil', 'No MSG', 'Perfect tea-time snack', 'Family pack value' ],
	  'diet'   => [ 'Vegan' => 0.6, 'Gluten-Free' => 0.3 ], 'tags' => [ 'Party Pack' ] ],
	[ 'p' => 'Snacks & Candy', 'n' => 'Vegan Snacks', 'w' => 1.2, 'price' => [ 79, 999 ],
	  'brands' => [ 'Green Bite', 'Plantful', 'Leafy Loot' ],
	  'items'  => [ 'Protein Bar', 'Roasted Chickpeas', 'Seaweed Crisps', 'Trail Mix', 'Date Energy Balls', 'Vegan Cookies', 'Quinoa Puffs', 'Coconut Chips' ],
	  'mods'   => [ 'Peanut Butter', 'Choco Chip', 'Himalayan Salt', 'Spicy', 'Berry Blast', '' ],
	  'packs'  => [ 'Pack of 6', 'Pack of 12', '150g', '300g' ],
	  'feats'  => [ '100% plant-based', 'High in protein', 'No refined sugar', 'Good source of fibre', 'Great for hikes and travel' ],
	  'diet'   => [ 'Vegan' => 1.0, 'Gluten-Free' => 0.5, 'Sugar-Free' => 0.2 ], 'tags' => [ 'Healthy', 'Hiking' ] ],
	[ 'p' => 'Snacks & Candy', 'n' => 'Dry Fruits & Nuts', 'w' => 1.0, 'price' => [ 149, 2499 ],
	  'brands' => [ 'Nutty Nest', 'Kashmir Kernel', 'Royal Orchard' ],
	  'items'  => [ 'Almonds', 'Cashews', 'Walnuts', 'Pistachios', 'Raisins', 'Medjool Dates', 'Mixed Dry Fruits', 'Dried Figs' ],
	  'mods'   => [ 'California', 'Roasted & Salted', 'Premium', 'Organic', '' ],
	  'packs'  => [ '250g', '500g', '1kg' ],
	  'feats'  => [ 'Hand-sorted and graded', 'Vacuum sealed for freshness', 'Rich in healthy fats', 'Ideal for gifting' ],
	  'diet'   => [ 'Vegan' => 1.0, 'Gluten-Free' => 1.0, 'Organic' => 0.2 ], 'tags' => [ 'Gift', 'Healthy', 'Festive' ] ],

	// ---------------- Beverages ----------------
	[ 'p' => 'Beverages', 'n' => 'Tea', 'w' => 1.0, 'price' => [ 99, 1299 ],
	  'brands' => [ 'Chaiwala Estates', 'Nilgiri Mist', 'Darjeeling Dawn' ],
	  'items'  => [ 'Masala Chai', 'Green Tea', 'Darjeeling First Flush', 'Chamomile Tea', 'Lemon Ginger Tea', 'Kashmiri Kahwa', 'Earl Grey', 'Tulsi Tea' ],
	  'mods'   => [ 'Loose Leaf', 'Organic', 'Premium', '' ],
	  'packs'  => [ '100g', '250g', '25 Bags', '100 Bags' ],
	  'feats'  => [ 'Estate-fresh leaves', 'Rich aroma', 'No artificial flavours', 'Brews strong and bright' ],
	  'diet'   => [ 'Vegan' => 1.0, 'Gluten-Free' => 1.0, 'Organic' => 0.25 ], 'tags' => [ 'Gift' ] ],
	[ 'p' => 'Beverages', 'n' => 'Coffee', 'w' => 1.0, 'price' => [ 199, 1999 ],
	  'brands' => [ 'Coorg Roasters', 'Bean Theory', 'Malabar Monsoon Co' ],
	  'items'  => [ 'Filter Coffee', 'Espresso Roast', 'Cold Brew Bags', 'Instant Coffee', 'French Press Blend', 'Chicory Blend' ],
	  'mods'   => [ 'Dark Roast', 'Medium Roast', 'Light Roast', 'Single Origin', '' ],
	  'packs'  => [ '200g', '500g', '1kg' ],
	  'feats'  => [ 'Freshly roasted in small batches', '100% Arabica', 'Notes of chocolate and caramel', 'Roast date on every pack' ],
	  'diet'   => [ 'Vegan' => 1.0, 'Gluten-Free' => 1.0 ], 'tags' => [ 'Gift' ] ],
	[ 'p' => 'Beverages', 'n' => 'Juices & Drinks', 'w' => 1.0, 'price' => [ 30, 599 ],
	  'brands' => [ 'Fresh Squeeze', 'Kokum Kraft', 'Hydra Sip' ],
	  'items'  => [ 'Mango Juice', 'Coconut Water', 'Kombucha', 'Jaljeera', 'Lemonade', 'Sparkling Water', 'Aam Panna', 'Electrolyte Drink' ],
	  'mods'   => [ 'No Added Sugar', 'Classic', 'Zero Sugar', 'Organic', '' ],
	  'packs'  => [ '200ml', '1L', 'Pack of 6' ],
	  'feats'  => [ 'Best served chilled', 'Made from real fruit', 'No preservatives', 'Naturally refreshing' ],
	  'diet'   => [ 'Vegan' => 0.9, 'Sugar-Free' => 0.3 ], 'tags' => [ 'Summer' ] ],

	// ---------------- Clothing ----------------
	[ 'p' => 'Clothing', 'n' => "Men's T-Shirts", 'w' => 1.2, 'price' => [ 299, 1499 ], 'var' => true,
	  'brands' => [ 'Urban Thread', 'Kora Basics', 'Trailcraft' ],
	  'items'  => [ 'Crew Neck T-Shirt', 'V-Neck T-Shirt', 'Polo T-Shirt', 'Graphic T-Shirt', 'Henley', 'Oversized T-Shirt' ],
	  'mods'   => [ 'Cotton', 'Slim Fit', 'Organic Cotton', 'Dri-Fit', '' ],
	  'colors' => [ 'Black', 'White', 'Navy', 'Olive', 'Grey', 'Maroon' ], 'sizes' => $APPAREL_SIZES,
	  'feats'  => [ '180 GSM breathable fabric', 'Pre-shrunk', 'Tagless neck label', 'Machine washable' ],
	  'tags'   => [ 'Casual', 'Everyday' ] ],
	[ 'p' => 'Clothing', 'n' => "Women's Tops", 'w' => 1.2, 'price' => [ 349, 1999 ], 'var' => true,
	  'brands' => [ 'Saanjh', 'Urban Thread', 'Lila Studio' ],
	  'items'  => [ 'Crop Top', 'Peplum Top', 'Tunic', 'Shirt', 'Tank Top', 'Wrap Top' ],
	  'mods'   => [ 'Cotton', 'Rayon', 'Linen', 'Printed', '' ],
	  'colors' => [ 'Black', 'White', 'Pink', 'Mustard', 'Teal', 'Lavender' ], 'sizes' => [ 'XS', 'S', 'M', 'L', 'XL' ],
	  'feats'  => [ 'Soft, breathable fabric', 'Relaxed fit', 'Easy care', 'Pairs with jeans or skirts' ],
	  'tags'   => [ 'Casual', 'Office Wear' ] ],
	[ 'p' => 'Clothing', 'n' => 'Jeans', 'w' => 1.0, 'price' => [ 799, 3499 ], 'var' => true,
	  'brands' => [ 'Denim District', 'Indigo Row', 'Urban Thread' ],
	  'items'  => [ 'Slim Fit Jeans', 'Straight Fit Jeans', 'Bootcut Jeans', 'Mom Jeans', 'Skinny Jeans', 'Relaxed Jeans' ],
	  'mods'   => [ 'Stretch', 'Distressed', 'Raw Denim', 'Classic', '' ],
	  'colors' => [ 'Dark Blue', 'Light Blue', 'Black', 'Grey' ], 'sizes' => [ '28', '30', '32', '34', '36' ],
	  'feats'  => [ '2% elastane for stretch', 'Five-pocket styling', 'Fade-resistant dye', 'Mid-rise waist' ],
	  'tags'   => [ 'Casual' ] ],
	[ 'p' => 'Clothing', 'n' => 'Ethnic Wear', 'w' => 1.2, 'price' => [ 999, 24999 ], 'var' => true,
	  'brands' => [ 'Rangrez', 'Saanjh', 'Banaras Loom', 'Royal Zari' ],
	  'items'  => [ 'Kurta', 'Anarkali Suit', 'Silk Saree', 'Lehenga', 'Sherwani', 'Nehru Jacket', 'Bandhgala', 'Kurta Pyjama Set' ],
	  'mods'   => [ 'Wedding', 'Festive', 'Silk', 'Handloom', 'Embroidered', 'Cotton' ],
	  'colors' => [ 'Red', 'Maroon', 'Gold', 'Ivory', 'Royal Blue', 'Emerald' ], 'sizes' => [ 'S', 'M', 'L', 'XL' ],
	  'free'   => [ 'Silk Saree', 'Lehenga' ],
	  'feats'  => [ 'Intricate zari work', 'Handcrafted by artisans', 'Comes with matching dupatta', 'Dry clean recommended' ],
	  'tags'   => [ 'Wedding', 'Festive' ] ],
	[ 'p' => 'Clothing', 'n' => 'Jackets', 'w' => 1.0, 'price' => [ 1299, 7999 ], 'var' => true,
	  'brands' => [ 'Trailcraft', 'Summit Peak', 'Urban Thread' ],
	  'items'  => [ 'Puffer Jacket', 'Windcheater', 'Denim Jacket', 'Fleece Jacket', 'Rain Jacket', 'Bomber Jacket' ],
	  'mods'   => [ 'Waterproof', 'Lightweight', 'Insulated', 'Packable', '' ],
	  'colors' => [ 'Black', 'Olive', 'Navy', 'Red', 'Grey' ], 'sizes' => [ 'S', 'M', 'L', 'XL' ],
	  'feats'  => [ 'Wind and water resistant', 'Zippered pockets', 'Packs into its own pouch', 'Adjustable hood' ],
	  'tags'   => [ 'Hiking', 'Winter', 'Waterproof' ] ],

	// ---------------- Footwear ----------------
	[ 'p' => 'Footwear', 'n' => 'Sneakers', 'w' => 1.0, 'price' => [ 999, 6999 ], 'var' => true,
	  'brands' => [ 'Stride Lab', 'Kicks Kulture', 'Urban Thread' ],
	  'items'  => [ 'Running Shoes', 'Canvas Sneakers', 'Court Sneakers', 'Slip-On Sneakers', 'Walking Shoes', 'Training Shoes' ],
	  'mods'   => [ 'Lightweight', 'Cushioned', 'Breathable', 'Classic', '' ],
	  'colors' => [ 'White', 'Black', 'Grey', 'Navy', 'Red' ], 'sizes' => $SHOE_SIZES,
	  'feats'  => [ 'Memory foam insole', 'Breathable mesh upper', 'Non-marking rubber sole', 'Lightweight build' ],
	  'tags'   => [ 'Running', 'Casual' ] ],
	[ 'p' => 'Footwear', 'n' => 'Hiking Boots', 'w' => 1.0, 'price' => [ 1999, 11999 ], 'var' => true,
	  'brands' => [ 'Summit Peak', 'Trailcraft', 'Himalaya Trek Co' ],
	  'items'  => [ 'Hiking Boots', 'Trekking Shoes', 'Trail Runners', 'Mountain Boots', 'Approach Shoes' ],
	  'mods'   => [ 'Waterproof', 'Mid-Ankle', 'All-Terrain', 'Lightweight', '' ],
	  'colors' => [ 'Brown', 'Black', 'Olive', 'Grey' ], 'sizes' => $SHOE_SIZES,
	  'feats'  => [ 'Deep-lug grip outsole', 'Waterproof membrane', 'Ankle support', 'Toe-protection cap' ],
	  'tags'   => [ 'Hiking', 'Waterproof', 'Trekking' ] ],
	[ 'p' => 'Footwear', 'n' => 'Sandals', 'w' => 0.9, 'price' => [ 399, 2999 ], 'var' => true,
	  'brands' => [ 'Stride Lab', 'Rangrez', 'Beachwalk' ],
	  'items'  => [ 'Floaters', 'Flip Flops', 'Kolhapuri Chappals', 'Sports Sandals', 'Juttis', 'Slides' ],
	  'mods'   => [ 'Leather', 'Comfort', 'Handcrafted', 'Classic', '' ],
	  'colors' => [ 'Tan', 'Black', 'Brown', 'Gold' ], 'sizes' => $SHOE_SIZES,
	  'feats'  => [ 'Cushioned footbed', 'Anti-slip sole', 'Water friendly', 'Handcrafted finish' ],
	  'tags'   => [ 'Summer', 'Festive' ] ],

	// ---------------- Outdoor & Hiking ----------------
	[ 'p' => 'Outdoor & Hiking', 'n' => 'Backpacks', 'w' => 1.0, 'price' => [ 799, 8999 ],
	  'brands' => [ 'Summit Peak', 'Trailcraft', 'Nomad Gear' ],
	  'items'  => [ 'Hiking Backpack', 'Daypack', 'Laptop Backpack', 'Rucksack', 'Hydration Pack', 'Travel Backpack' ],
	  'mods'   => [ '20L', '30L', '45L', '60L', 'Waterproof' ],
	  'colors' => [ 'Black', 'Olive', 'Blue', 'Orange', 'Grey' ],
	  'feats'  => [ 'Padded shoulder straps', 'Rain cover included', 'Multiple compartments', 'Chest and hip belts' ],
	  'tags'   => [ 'Hiking', 'Travel', 'Waterproof' ] ],
	[ 'p' => 'Outdoor & Hiking', 'n' => 'Tents & Camping', 'w' => 1.0, 'price' => [ 499, 14999 ],
	  'brands' => [ 'Nomad Gear', 'Himalaya Trek Co', 'Campfire & Co' ],
	  'items'  => [ 'Dome Tent', 'Sleeping Bag', 'Camping Mat', 'Headlamp', 'Camping Stove', 'Trekking Poles', 'Camping Chair', 'Lantern' ],
	  'mods'   => [ '2-Person', '4-Person', 'Ultralight', 'Rechargeable', 'Foldable', '' ],
	  'feats'  => [ 'Sets up in minutes', 'Weather resistant', 'Carry bag included', 'Tested at high altitude' ],
	  'tags'   => [ 'Camping', 'Hiking', 'Trekking' ] ],
	[ 'p' => 'Outdoor & Hiking', 'n' => 'Water Bottles', 'w' => 0.9, 'price' => [ 199, 2499 ],
	  'brands' => [ 'Hydra Sip', 'Nomad Gear', 'Tamra' ],
	  'items'  => [ 'Insulated Bottle', 'Steel Bottle', 'Collapsible Bottle', 'Filter Bottle', 'Copper Bottle', 'Sipper' ],
	  'mods'   => [ '500ml', '750ml', '1L', 'Leak-Proof', '' ],
	  'colors' => [ 'Black', 'Silver', 'Blue', 'Green', 'Copper' ],
	  'feats'  => [ 'Keeps drinks cold for 24 hours', 'BPA free', 'Leak-proof lid', 'Fits car cup holders' ],
	  'tags'   => [ 'Hiking', 'Gym', 'Travel' ] ],

	// ---------------- Electronics ----------------
	[ 'p' => 'Electronics', 'n' => 'Headphones', 'w' => 1.0, 'price' => [ 499, 24999 ],
	  'brands' => [ 'SoundNest', 'Boom Labs', 'Auralis' ],
	  'items'  => [ 'Wireless Earbuds', 'Over-Ear Headphones', 'Neckband', 'On-Ear Headphones', 'Gaming Headset', 'Sports Earbuds' ],
	  'mods'   => [ 'Noise Cancelling', 'Bluetooth 5.3', 'Bass Boost', 'Low Latency', '' ],
	  'colors' => [ 'Black', 'White', 'Blue', 'Beige' ],
	  'feats'  => [ 'Up to 40 hours playback', 'Fast charging', 'IPX5 sweat resistance', 'Dual-device pairing' ],
	  'tags'   => [ 'Wireless', 'Gym' ] ],
	[ 'p' => 'Electronics', 'n' => 'Phone Accessories', 'w' => 1.0, 'price' => [ 149, 3999 ],
	  'brands' => [ 'ChargeUp', 'Boom Labs', 'GripX' ],
	  'items'  => [ 'Fast Charger', 'USB-C Cable', 'Power Bank', 'Phone Case', 'Screen Protector', 'Car Mount', 'Wireless Charger' ],
	  'mods'   => [ '20W', '65W', '10000mAh', '20000mAh', 'Braided', 'Magnetic', '' ],
	  'feats'  => [ 'BIS certified', 'Overcharge protection', 'Compact and travel friendly', 'Works with most phones' ],
	  'tags'   => [ 'Travel' ] ],
	[ 'p' => 'Electronics', 'n' => 'Smartwatches', 'w' => 0.9, 'price' => [ 1499, 29999 ],
	  'brands' => [ 'Pulse', 'Auralis', 'FitNest' ],
	  'items'  => [ 'Smartwatch', 'Fitness Band', 'GPS Watch', 'Kids Smartwatch' ],
	  'mods'   => [ 'AMOLED', 'Bluetooth Calling', 'GPS', 'SpO2', '' ],
	  'colors' => [ 'Black', 'Silver', 'Rose Gold', 'Blue' ],
	  'feats'  => [ 'Heart rate and sleep tracking', '7-day battery', '100+ sports modes', '5 ATM water resistance' ],
	  'tags'   => [ 'Gym', 'Running', 'Hiking' ] ],

	// ---------------- Home & Kitchen ----------------
	[ 'p' => 'Home & Kitchen', 'n' => 'Cookware', 'w' => 1.0, 'price' => [ 299, 9999 ],
	  'brands' => [ 'Rasoi Pro', 'Hearth & Home', 'Tamra' ],
	  'items'  => [ 'Pressure Cooker', 'Non-Stick Tawa', 'Cast Iron Kadai', 'Frying Pan', 'Cookware Set', 'Steel Casserole', 'Idli Maker' ],
	  'mods'   => [ '3L', '5L', 'Induction-Ready', 'Hard Anodised', 'Stainless Steel', '' ],
	  'feats'  => [ 'Works on gas and induction', 'Even heat distribution', 'Cool-touch handles', '5-year warranty' ],
	  'tags'   => [ 'Kitchen Essentials' ] ],
	[ 'p' => 'Home & Kitchen', 'n' => 'Home Decor', 'w' => 1.0, 'price' => [ 199, 7999 ],
	  'brands' => [ 'Hearth & Home', 'Mitti Studio', 'Deco Daze' ],
	  'items'  => [ 'Scented Candle', 'Brass Diya', 'Wall Clock', 'Cushion Cover', 'Table Lamp', 'Ceramic Vase', 'Wall Art', 'Fairy Lights' ],
	  'mods'   => [ 'Handcrafted', 'Set of 2', 'Boho', 'Minimal', 'Festive', '' ],
	  'feats'  => [ 'Handmade by Indian artisans', 'Ready to gift', 'Adds warmth to any room', 'Easy to clean' ],
	  'tags'   => [ 'Gift', 'Festive', 'Diwali' ] ],

	// ---------------- Beauty & Personal Care ----------------
	[ 'p' => 'Beauty & Personal Care', 'n' => 'Skincare', 'w' => 1.0, 'price' => [ 149, 2999 ],
	  'brands' => [ 'Glow Theory', 'Ayur Root', 'Pure Petal' ],
	  'items'  => [ 'Face Wash', 'Moisturiser', 'Sunscreen SPF 50', 'Vitamin C Serum', 'Night Cream', 'Clay Face Mask', 'Lip Balm', 'Toner' ],
	  'mods'   => [ 'Oil-Free', 'Hydrating', 'Niacinamide', 'Ayurvedic', 'Sensitive Skin', '' ],
	  'packs'  => [ '50ml', '100ml', '200ml' ],
	  'feats'  => [ 'Dermatologically tested', 'Paraben free', 'Suitable for all skin types', 'Fragrance free' ],
	  'diet'   => [ 'Vegan' => 0.5 ], 'tags' => [ 'Cruelty-Free', 'Gift' ] ],
	[ 'p' => 'Beauty & Personal Care', 'n' => 'Haircare', 'w' => 0.9, 'price' => [ 149, 1999 ],
	  'brands' => [ 'Ayur Root', 'Glow Theory', 'Kesh Kala' ],
	  'items'  => [ 'Shampoo', 'Conditioner', 'Hair Oil', 'Hair Mask', 'Hair Serum', 'Dry Shampoo' ],
	  'mods'   => [ 'Onion', 'Argan', 'Anti-Dandruff', 'Coconut', 'Sulphate-Free', '' ],
	  'packs'  => [ '100ml', '250ml', '500ml' ],
	  'feats'  => [ 'Reduces hair fall', 'Silicone free', 'Nourishes from root to tip', 'Safe for coloured hair' ],
	  'diet'   => [ 'Vegan' => 0.4 ], 'tags' => [ 'Cruelty-Free' ] ],

	// ---------------- Books ----------------
	[ 'p' => 'Books', 'n' => 'Fiction', 'w' => 0.8, 'price' => [ 199, 899 ], 'kind' => 'book',
	  'adjs'    => [ 'Silent', 'Last', 'Hidden', 'Monsoon', 'Crimson', 'Forgotten', 'Midnight', 'Paper', 'Salt', 'Lantern' ],
	  'nouns'   => [ 'River', 'Garden', 'Letter', 'Kingdom', 'Station', 'Orchard', 'Cartographer', 'Tide', 'Weaver', 'Courtyard' ],
	  'authors' => [ 'Ananya Rao', 'Ishaan Verma', 'Meera Iyer', 'Rohan Das', 'Kavya Menon', 'Nila Krishnan', 'Farah Qureshi' ],
	  'feats'   => [ 'A gripping page-turner', 'Shortlisted for a national prize', 'Perfect weekend read', 'Beautifully written' ],
	  'tags'    => [ 'Bestseller', 'Gift' ] ],
	[ 'p' => 'Books', 'n' => 'Non-Fiction', 'w' => 0.8, 'price' => [ 249, 1299 ], 'kind' => 'book',
	  'titles'  => [ 'The Art of Focus', 'Money Without Stress', "The Trekker's Handbook", 'Cooking from an Indian Kitchen', 'Designing Products People Love', 'The Calm Founder', 'Small Habits, Big Life', 'Stories That Sell', 'Himalayan Trails', 'The Curious Investor' ],
	  'subs'    => [ 'A Practical Guide', 'Second Edition', 'Illustrated Edition', '', '' ],
	  'authors' => [ 'Aditi Shah', 'Karan Malhotra', 'Priya Nair', 'Sameer Joshi', 'Dev Banerjee' ],
	  'feats'   => [ 'Actionable and practical', 'Backed by research', 'Includes worked examples', 'Easy to read' ],
	  'tags'    => [ 'Bestseller' ] ],

	// ---------------- Sports & Fitness ----------------
	[ 'p' => 'Sports & Fitness', 'n' => 'Yoga', 'w' => 0.9, 'price' => [ 199, 3999 ],
	  'brands' => [ 'Prana Fit', 'Asana Co', 'FitNest' ],
	  'items'  => [ 'Yoga Mat', 'Yoga Block', 'Yoga Strap', 'Meditation Cushion', 'Yoga Wheel' ],
	  'mods'   => [ '6mm', '8mm', 'Anti-Slip', 'Eco TPE', 'Cork', '' ],
	  'colors' => [ 'Purple', 'Teal', 'Black', 'Pink', 'Grey' ],
	  'feats'  => [ 'Non-slip texture', 'Eco-friendly material', 'Lightweight and portable', 'Easy to clean' ],
	  'tags'   => [ 'Yoga', 'Home Workout' ] ],
	[ 'p' => 'Sports & Fitness', 'n' => 'Gym Equipment', 'w' => 1.0, 'price' => [ 299, 19999 ],
	  'brands' => [ 'IronHouse', 'FitNest', 'Prana Fit' ],
	  'items'  => [ 'Dumbbell Set', 'Resistance Bands', 'Kettlebell', 'Skipping Rope', 'Adjustable Bench', 'Pull-Up Bar', 'Ab Roller', 'Foam Roller' ],
	  'mods'   => [ '5kg', '10kg', '20kg', 'Adjustable', 'Heavy Duty', '' ],
	  'feats'  => [ 'Built for daily training', 'Anti-rust coating', 'Ergonomic grip', 'Space-saving design' ],
	  'tags'   => [ 'Gym', 'Home Workout' ] ],
];

/* ============================================================================
 * Helpers
 * ========================================================================== */

function mrc_rand(): float { return mt_rand() / mt_getrandmax(); }
function mrc_chance( float $p ): bool { return mrc_rand() < $p; }
function mrc_pick( array $a ) { return $a[ mt_rand( 0, count( $a ) - 1 ) ]; }
function mrc_pick_n( array $a, int $n ): array { shuffle( $a ); return array_slice( $a, 0, min( $n, count( $a ) ) ); }

/** Indian-retail style price endings: 49, 299, 1,499 … */
function mrc_round( float $p ): int {
	if ( $p >= 1000 ) { return (int) max( 999, round( $p / 100 ) * 100 - 1 ); }
	if ( $p >= 100 )  { return (int) max( 99, round( $p / 10 ) * 10 - 1 ); }
	return (int) max( 10, round( $p ) );
}

/** Random price in range, skewed towards the cheaper end. */
function mrc_price( float $min, float $max ): int {
	return mrc_round( $min + ( $max - $min ) * pow( mrc_rand(), 1.6 ) );
}

function mrc_term( string $name, string $tax, int $parent = 0 ): int {
	static $cache = [];
	$key = "$tax|$parent|$name";
	if ( isset( $cache[ $key ] ) ) { return $cache[ $key ]; }
	$slug = sanitize_title( $parent ? get_term( $parent )->slug . '-' . $name : $name );
	$t    = get_term_by( 'slug', $slug, $tax );
	if ( $t ) { return $cache[ $key ] = (int) $t->term_id; }
	$r = wp_insert_term( $name, $tax, [ 'slug' => $slug, 'parent' => $parent ] );
	if ( is_wp_error( $r ) ) {
		if ( isset( $r->error_data['term_exists'] ) ) { return $cache[ $key ] = (int) $r->error_data['term_exists']; }
		WP_CLI::error( "Term '$name' ($tax): " . $r->get_error_message() );
	}
	return $cache[ $key ] = (int) $r['term_id'];
}

function mrc_term_slug( string $name, string $tax ): string {
	return get_term( mrc_term( $name, $tax ), $tax )->slug;
}

/** Ensure a global attribute (pa_*) exists and is registered for this request. */
function mrc_attribute_tax( string $label, string $slug ): string {
	global $MRC_ATTR_IDS;
	$tax = 'pa_' . $slug;
	$id  = wc_attribute_taxonomy_id_by_name( $slug );
	if ( ! $id ) {
		$id = wc_create_attribute( [ 'name' => $label, 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false ] );
		if ( is_wp_error( $id ) ) { WP_CLI::error( "Attribute $label: " . $id->get_error_message() ); }
	}
	if ( ! taxonomy_exists( $tax ) ) {
		register_taxonomy( $tax, [ 'product' ], [ 'hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false ] );
	}
	$MRC_ATTR_IDS[ $tax ] = (int) $id;
	return $tax;
}

function mrc_attr( string $tax, array $values, bool $variation = false, int $position = 0 ): WC_Product_Attribute {
	global $MRC_ATTR_IDS;
	$a = new WC_Product_Attribute();
	$a->set_id( $MRC_ATTR_IDS[ $tax ] );
	$a->set_name( $tax );
	$a->set_options( array_map( static fn( $v ) => mrc_term( $v, $tax ), $values ) );
	$a->set_position( $position );
	$a->set_visible( true );
	$a->set_variation( $variation );
	return $a;
}

/** One generated placeholder image per leaf category (GD), reused across its products. */
function mrc_placeholder( string $label, array $rgb ): int {
	static $cache = [];
	$slug = 'mercora-seed-' . sanitize_title( $label );
	if ( isset( $cache[ $slug ] ) ) { return $cache[ $slug ]; }

	$found = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 1, 'fields' => 'ids',
		'meta_key' => '_mercora_seed_image', 'meta_value' => $slug ] );
	if ( $found ) { return $cache[ $slug ] = (int) $found[0]; }
	if ( ! function_exists( 'imagecreatetruecolor' ) ) { return $cache[ $slug ] = 0; }

	$s  = imagecreatetruecolor( 200, 200 );
	$bg = imagecolorallocate( $s, $rgb[0], $rgb[1], $rgb[2] );
	$fg = imagecolorallocate( $s, 255, 255, 255 );
	imagefill( $s, 0, 0, $bg );
	$lines = explode( "\n", wordwrap( $label, 18, "\n", true ) );
	$lh    = imagefontheight( 5 ) + 4;
	$y     = (int) ( ( 200 - count( $lines ) * $lh ) / 2 );
	foreach ( $lines as $line ) {
		imagestring( $s, 5, (int) ( ( 200 - strlen( $line ) * imagefontwidth( 5 ) ) / 2 ), $y, $line, $fg );
		$y += $lh;
	}
	imagestring( $s, 2, (int) ( ( 200 - 7 * imagefontwidth( 2 ) ) / 2 ), 176, 'MERCORA', $fg );
	$img = imagescale( $s, 800, 800, IMG_NEAREST_NEIGHBOUR );

	$up   = wp_upload_dir();
	$file = trailingslashit( $up['path'] ) . $slug . '.png';
	imagepng( $img, $file );
	imagedestroy( $s );
	imagedestroy( $img );

	$att = wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => $label, 'post_status' => 'inherit' ], $file );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $file ) );
	update_post_meta( $att, '_mercora_seed_image', $slug );
	return $cache[ $slug ] = (int) $att;
}

/* ============================================================================
 * 1. Build the product specs (pure data, deterministic)
 * ========================================================================== */

function mrc_make_spec( int $li, array $l, array &$seen ): array {
	$book = ( $l['kind'] ?? '' ) === 'book';
	$parts = [];
	for ( $try = 0; $try < 15; $try++ ) {
		if ( $book ) {
			if ( isset( $l['titles'] ) ) {
				$sub  = mrc_pick( $l['subs'] );
				$name = mrc_pick( $l['titles'] ) . ( $sub ? " ($sub)" : '' );
			} else {
				$a = mrc_pick( $l['adjs'] ); $n = mrc_pick( $l['nouns'] );
				$name = mrc_pick( [ "The $a $n", "$a $n", "The $n of $a Hours", "A $a $n" ] );
			}
		} else {
			$parts = [ mrc_pick( $l['brands'] ), mrc_pick( $l['mods'] ), mrc_pick( $l['items'] ), ! empty( $l['packs'] ) ? mrc_pick( $l['packs'] ) : '' ];
			$name  = trim( preg_replace( '/\s+/', ' ', "{$parts[0]} {$parts[1]} {$parts[2]}" ) ) . ( $parts[3] ? " – {$parts[3]}" : '' );
		}
		if ( ! isset( $seen[ $name ] ) ) { break; }
	}
	$seen[ $name ] = true;

	// Bigger packs cost proportionally more (100g < 200g < 400g).
	$price = mrc_price( $l['price'][0], $l['price'][1] );
	if ( ! $book && ! empty( $l['packs'] ) ) {
		$factors = [ 1.0, 1.8, 3.2, 5.0 ];
		$fmax    = $factors[ count( $l['packs'] ) - 1 ];
		$base    = mrc_price( $l['price'][0], max( $l['price'][0] * 1.2, $l['price'][1] / $fmax ) );
		$price   = mrc_round( $base * $factors[ array_search( $parts[3], $l['packs'], true ) ] );
	}
	$r     = mrc_rand();
	$stock = $r < 0.08 ? 'out' : ( $r < 0.15 ? 'low' : ( $r < 0.17 ? 'backorder' : 'normal' ) );

	$diet = [];
	foreach ( $l['diet'] ?? [] as $d => $p ) { if ( mrc_chance( $p ) ) { $diet[] = $d; } }

	$tags = array_values( array_filter( $l['tags'] ?? [], static fn() => mrc_chance( 0.35 ) ) );
	if ( mrc_chance( 0.10 ) ) { $tags[] = 'Bestseller'; }
	if ( mrc_chance( 0.10 ) ) { $tags[] = 'New Arrival'; }
	$tags = array_values( array_unique( array_merge( $tags, $diet ) ) );

	$item     = $parts[2] ?? '';
	$is_free  = in_array( $item, $l['free'] ?? [], true );
	$variable = ! empty( $l['var'] ) && ! $is_free && mrc_chance( 0.55 );

	$spec = [
		'leaf'     => $li,
		'name'     => $name,
		'parts'    => $parts,
		'brand'    => $book ? null : $parts[0],
		'book'     => $book,
		'author'   => $book ? mrc_pick( $l['authors'] ) : null,
		'format'   => $book ? mrc_pick( [ 'Paperback', 'Paperback', 'Hardcover' ] ) : null,
		'price'    => $price,
		'sale'     => mrc_chance( 0.22 ) ? min( $price - 1, mrc_price( $price * 0.6, $price * 0.9 ) ) : null,
		'stock'    => $stock,
		'qty'      => $stock === 'low' ? mt_rand( 1, 3 ) : mt_rand( 8, 150 ),
		'diet'     => $diet,
		'tags'     => $tags,
		'feats'    => mrc_pick_n( $l['feats'], 3 ),
		'featured' => mrc_chance( 0.05 ),
		'missing'  => mrc_chance( 0.05 ),  // no brand attribute, no description, no image
		'sales'    => (int) round( pow( mrc_rand(), 3 ) * 900 ),
		'variable' => $variable,
		'color'    => null, 'size' => null, 'colors' => [], 'sizes' => [],
	];

	if ( $variable ) {
		$spec['colors'] = mrc_pick_n( $l['colors'], mt_rand( 2, 3 ) );
		$start          = mt_rand( 0, count( $l['sizes'] ) - 3 );
		$spec['sizes']  = array_slice( $l['sizes'], $start, 3 );
	} else {
		if ( ! empty( $l['colors'] ) ) { $spec['color'] = mrc_pick( $l['colors'] ); }
		if ( $is_free ) { $spec['size'] = 'Free Size'; }
		elseif ( ! empty( $l['sizes'] ) ) { $spec['size'] = mrc_pick( $l['sizes'] ); }
	}
	return $spec;
}

$dup_count  = (int) round( $mrc_count * 0.04 );
$base_count = $mrc_count - $dup_count;

// Distribute products across leaf categories by weight (largest-remainder method).
$total_w = array_sum( array_map( static fn( $l ) => $l['w'] ?? 1, $MRC_CATALOG ) );
$quotas  = []; $rems = [];
foreach ( $MRC_CATALOG as $i => $l ) {
	$exact       = $base_count * ( $l['w'] ?? 1 ) / $total_w;
	$quotas[ $i ] = (int) floor( $exact );
	$rems[ $i ]   = $exact - $quotas[ $i ];
}
arsort( $rems );
$left = $base_count - array_sum( $quotas );
foreach ( array_keys( $rems ) as $i ) { if ( $left-- <= 0 ) { break; } $quotas[ $i ]++; }

$seen  = [];
$specs = [];
foreach ( $MRC_CATALOG as $i => $l ) {
	for ( $k = 0; $k < $quotas[ $i ]; $k++ ) { $specs[] = mrc_make_spec( $i, $MRC_CATALOG[ $i ], $seen ); }
}

// Near-duplicates: same product in a bigger pack, or the same item from a different brand.
$candidates = array_values( array_filter( $specs, static fn( $s ) => ! $s['book'] ) );
$made = 0;
for ( $attempt = 0; $made < $dup_count && $candidates && $attempt < $dup_count * 20; $attempt++ ) {
	$src = mrc_pick( $candidates );
	$l   = $MRC_CATALOG[ $src['leaf'] ];
	$dup = $src;
	if ( mrc_chance( 0.5 ) || count( $l['brands'] ) < 2 ) {
		$suffix       = mrc_pick( [ ' (Pack of 2)', ' – Value Pack', ' – Combo Offer' ] );
		$dup['name']  = $src['name'] . $suffix;
		$dup['price'] = mrc_price( $src['price'] * 1.7, $src['price'] * 1.9 );
		$dup['sale']  = null;
	} else {
		$other          = mrc_pick( array_values( array_diff( $l['brands'], [ $src['brand'] ] ) ) );
		$dup['brand']   = $other;
		$dup['parts'][0] = $other;
		$dup['name']    = str_replace( $src['brand'], $other, $src['name'] );
		$dup['price']   = mrc_price( $src['price'] * 0.85, $src['price'] * 1.15 );
	}
	if ( isset( $seen[ $dup['name'] ] ) ) { continue; }
	$seen[ $dup['name'] ] = true;
	$dup['featured'] = false;
	$specs[]         = $dup;
	$made++;
}

shuffle( $specs );
foreach ( $specs as $i => &$s ) { $s['sku'] = sprintf( 'MRC-%05d', $i + 1 ); }
unset( $s );

if ( getenv( 'MERCORA_SEED_DRY_RUN' ) ) { return $specs; }

/* ============================================================================
 * 2. Write to WooCommerce
 * ========================================================================== */

if ( ! defined( 'WP_IMPORTING' ) ) { define( 'WP_IMPORTING', true ); }
wp_defer_term_counting( true );

if ( $mrc_reset ) {
	$ids = get_posts( [ 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids',
		'meta_key' => '_mercora_seed', 'meta_value' => '1' ] );
	WP_CLI::log( sprintf( '==> Reset: deleting %d seeded products', count( $ids ) ) );
	foreach ( $ids as $id ) {
		$p = wc_get_product( $id );
		if ( $p ) { $p->delete( true ); }
	}
}

$GLOBALS['MRC_ATTR_IDS'] = [];
mrc_attribute_tax( 'Brand', 'brand' );
mrc_attribute_tax( 'Colour', 'color' );
mrc_attribute_tax( 'Size', 'size' );
mrc_attribute_tax( 'Dietary', 'dietary' );
mrc_attribute_tax( 'Author', 'book-author' );
mrc_attribute_tax( 'Format', 'format' );

$stats = [ 'created' => 0, 'skipped' => 0, 'variable' => 0, 'variations' => 0, 'out' => 0, 'low' => 0, 'backorder' => 0, 'sale' => 0 ];
$total = count( $specs );
WP_CLI::log( "==> Seeding $total products across " . count( $MRC_CATALOG ) . ' categories' );

foreach ( $specs as $n => $s ) {
	if ( wc_get_product_id_by_sku( $s['sku'] ) ) { $stats['skipped']++; continue; }

	$l      = $MRC_CATALOG[ $s['leaf'] ];
	$parent = mrc_term( $l['p'], 'product_cat' );
	$leaf   = mrc_term( $l['n'], 'product_cat', $parent );

	$p = $s['variable'] ? new WC_Product_Variable() : new WC_Product_Simple();
	$p->set_name( $s['name'] );
	$p->set_sku( $s['sku'] );
	$p->set_status( 'publish' );
	$p->set_category_ids( [ $parent, $leaf ] );
	$p->set_tag_ids( array_map( static fn( $t ) => mrc_term( $t, 'product_tag' ), $s['tags'] ) );
	$p->set_featured( $s['featured'] );
	$p->set_total_sales( $s['sales'] );

	if ( ! $s['missing'] ) {
		$by = $s['book'] ? ' by ' . $s['author'] : ( $s['brand'] ? ' by ' . $s['brand'] : '' );
		$p->set_short_description( esc_html( $s['feats'][0] . '. ' . ( $s['feats'][1] ?? '' ) . '.' ) );
		$p->set_description(
			'<p>' . esc_html( $s['name'] . $by . '. ' . $MRC_BLURBS[ $l['p'] ] ) . '</p><ul><li>'
			. implode( '</li><li>', array_map( 'esc_html', $s['feats'] ) ) . '</li></ul>'
		);
		$p->set_image_id( mrc_placeholder( $l['n'], $MRC_PALETTE[ $l['p'] ] ) );
	}

	$attrs = [];
	if ( $s['brand'] && ! $s['missing'] ) { $attrs[] = mrc_attr( 'pa_brand', [ $s['brand'] ], false, count( $attrs ) ); }
	if ( $s['book'] ) {
		$attrs[] = mrc_attr( 'pa_book-author', [ $s['author'] ], false, count( $attrs ) );
		$attrs[] = mrc_attr( 'pa_format', [ $s['format'] ], false, count( $attrs ) );
	}
	if ( $s['diet'] ) { $attrs[] = mrc_attr( 'pa_dietary', $s['diet'], false, count( $attrs ) ); }
	if ( $s['variable'] ) {
		$attrs[] = mrc_attr( 'pa_color', $s['colors'], true, count( $attrs ) );
		$attrs[] = mrc_attr( 'pa_size', $s['sizes'], true, count( $attrs ) );
	} else {
		if ( $s['color'] ) { $attrs[] = mrc_attr( 'pa_color', [ $s['color'] ], false, count( $attrs ) ); }
		if ( $s['size'] )  { $attrs[] = mrc_attr( 'pa_size', [ $s['size'] ], false, count( $attrs ) ); }
	}
	$p->set_attributes( $attrs );

	if ( ! $s['variable'] ) {
		$p->set_regular_price( (string) $s['price'] );
		if ( $s['sale'] ) { $p->set_sale_price( (string) $s['sale'] ); }
		$p->set_manage_stock( true );
		$p->set_stock_quantity( in_array( $s['stock'], [ 'out', 'backorder' ], true ) ? 0 : $s['qty'] );
		$p->set_backorders( $s['stock'] === 'backorder' ? 'notify' : 'no' );
	}

	$p->update_meta_data( '_mercora_seed', '1' );
	$pid = $p->save();

	if ( $s['variable'] ) {
		$stats['variable']++;
		foreach ( $s['colors'] as $c ) {
			foreach ( $s['sizes'] as $zi => $z ) {
				$bump = $zi === 2 ? (int) ( ceil( $s['price'] * 0.05 / 10 ) * 10 ) : 0;   // largest size costs a little more
				$v    = new WC_Product_Variation();
				$v->set_parent_id( $pid );
				$v->set_attributes( [ 'pa_color' => mrc_term_slug( $c, 'pa_color' ), 'pa_size' => mrc_term_slug( $z, 'pa_size' ) ] );
				$v->set_sku( $s['sku'] . '-' . sanitize_title( $c ) . '-' . sanitize_title( $z ) );
				$v->set_regular_price( (string) ( $s['price'] + $bump ) );
				if ( $s['sale'] ) { $v->set_sale_price( (string) ( $s['sale'] + $bump ) ); }
				$v->set_manage_stock( true );
				$qty = match ( $s['stock'] ) {
					'out', 'backorder' => 0,
					'low'              => mt_rand( 0, 2 ),
					default            => mrc_chance( 0.2 ) ? 0 : mt_rand( 2, 40 ),   // some sizes sold out
				};
				$v->set_stock_quantity( $qty );
				$v->set_backorders( $s['stock'] === 'backorder' ? 'notify' : 'no' );
				$v->set_status( 'publish' );
				$v->update_meta_data( '_mercora_seed', '1' );
				$v->save();
				$stats['variations']++;
			}
		}
		WC_Product_Variable::sync( $pid );
	}

	$stats['created']++;
	if ( in_array( $s['stock'], [ 'out', 'low', 'backorder' ], true ) ) { $stats[ $s['stock'] ]++; }
	if ( $s['sale'] ) { $stats['sale']++; }
	if ( $mrc_limit && $stats["created"] >= $mrc_limit ) { break; }

	if ( ( $n + 1 ) % 50 === 0 ) {
		WP_CLI::log( sprintf( '    %d / %d', $n + 1, $total ) );
		wp_cache_flush();
	}
}

wp_defer_term_counting( false );
wc_delete_product_transients();

WP_CLI::log( "MERCORA_CREATED=" . $stats["created"] );
WP_CLI::success( sprintf(
	'Created %d (skipped %d existing) · %d variable with %d variations · %d out of stock · %d low stock · %d backorder · %d on sale',
	$stats['created'], $stats['skipped'], $stats['variable'], $stats['variations'], $stats['out'], $stats['low'], $stats['backorder'], $stats['sale']
) );
