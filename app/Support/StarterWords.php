<?php

namespace App\Support;

/**
 * The trade's own vocabulary, so a shop's first day is not an empty box —
 * Soran, 2026-09-29: *"make Full of words Dictionary to more smartest"*.
 *
 * A shop that has just been set up has written nothing, so the word help can
 * do nothing until somebody has typed for a week — which is exactly when they
 * need it most. This carries them until their own catalogue takes over.
 *
 * ⚠️ **IT OFFERS AND IT NEVER CORRECTS.** These words finish what somebody is
 * typing. They are never read by `ProductName::tidy()` and never by
 * `ProductName::spellingsItKnows()`. Section 9's rule is untouched: the shop's
 * catalogue is the only authority on what a word IS, and this only saves
 * keystrokes on the way there.
 *
 * ⚠️ **The shop always outranks it** — see `WordList::for()`, where a word the
 * shop has written keeps its own casing and its own count, and the starter
 * word for it is dropped rather than offered twice.
 *
 * ⚠️ **Nothing here for customers or suppliers.** There is no such thing as a
 * common person's name, and guessing at one would be useless and rude.
 */
final class StarterWords
{
    /** @return list<string> */
    public static function for(string $kind): array
    {
        return match ($kind) {
            'products' => [...self::BRANDS, ...self::THINGS, ...self::QUALITIES, ...self::COLOURS],
            'devices' => self::DEVICES,
            'expenses' => self::COSTS,
            default => [],
        };
    }

    /** The makes on the shelves of a phone and computer shop in Iraq. */
    private const BRANDS = [
        'Anker', 'Baseus', 'Mcdodo', 'Joyroom', 'Sikenai', 'Hoco', 'Ldnio', 'Remax', 'Awei',
        'Oraimo', 'Porodo', 'Devia', 'Usams', 'Yesido', 'Powerology', 'Eufy', 'Soundcore',
        'Rapoo', 'Logitech', 'Razer', 'JBL', 'Sony', 'Samsung', 'Xiaomi', 'Redmi', 'Poco',
        'Huawei', 'Honor', 'Oppo', 'Realme', 'Infinix', 'Tecno', 'Nokia', 'Apple', 'iPhone',
        'iPad', 'Asus', 'Lenovo', 'Acer', 'Dell', 'MSI', 'Gigabyte', 'Intel', 'AMD',
        'Kingston', 'Sandisk', 'Adata', 'Seagate', 'Toshiba', 'Olax', 'Go-Des',
    ];

    /** What a shop like this actually sells. */
    private const THINGS = [
        'Cable', 'Charger', 'Adapter', 'Converter', 'Power', 'Bank', 'Earphone', 'Earbuds',
        'Headset', 'Headphone', 'Speaker', 'Microphone', 'Mouse', 'Keyboard', 'Monitor',
        'Laptop', 'Tablet', 'Screen', 'Protector', 'Glass', 'Case', 'Cover', 'Holder',
        'Stand', 'Mount', 'Flash', 'Memory', 'Card', 'Reader', 'Router', 'Modem', 'Receiver',
        'Antenna', 'Controller', 'Console', 'Joystick', 'Battery', 'Cooler', 'Fan', 'Light',
        'Lamp', 'Tripod', 'Selfie', 'Stick', 'Smartwatch', 'Watch', 'Band', 'Strap', 'Hub',
        'Splitter', 'Extension', 'Socket', 'Dock', 'Station', 'Stylus', 'Bag', 'Pouch',
        'Cleaner', 'Kit', 'Service', 'Game', 'Pass', 'Sim', 'Cartridge', 'Toner', 'Printer',
    ];

    /** The words that tell two of them apart. */
    private const QUALITIES = [
        'Wireless', 'Bluetooth', 'Magnetic', 'Fast', 'Original', 'Copy', 'Master', 'Tempered',
        'Silicone', 'Leather', 'Transparent', 'Matte', 'Gaming', 'Portable', 'Rechargeable',
        'Digital', 'Dual', 'Mini', 'Pro', 'Max', 'Plus', 'Lite', 'Ultra', 'Premium', 'True',
        'Type-C', 'USB', 'LTG', 'Lightning', 'Micro', 'HDMI', 'VGA', 'AUX', 'Ethernet',
        'PD', 'QC', 'NC', 'ANC', 'RGB', 'LED', 'OLED', 'LCD', 'SSD', 'HDD', 'GB', 'TB',
        'mAh', 'Hz', 'LTE', 'WiFi', '4G', '5G', 'In-Ear', 'Over-Ear', 'Port', 'Watt',
    ];

    private const COLOURS = [
        'Black', 'White', 'Blue', 'Red', 'Green', 'Gold', 'Silver', 'Grey',
        'Pink', 'Purple', 'Orange', 'Yellow', 'Navy', 'Beige',
    ];

    /** What comes over the counter onto a repair bench. */
    private const DEVICES = [
        'iPhone', 'iPad', 'MacBook', 'iMac', 'Samsung', 'Galaxy', 'Note', 'Xiaomi', 'Redmi',
        'Poco', 'Huawei', 'Honor', 'Oppo', 'Realme', 'Vivo', 'OnePlus', 'Infinix', 'Tecno',
        'Nokia', 'Laptop', 'Lenovo', 'Dell', 'Asus', 'Acer', 'Toshiba', 'PlayStation',
        'Xbox', 'Nintendo', 'Switch', 'Console', 'Controller', 'Tablet', 'Smartwatch',
        'Printer', 'Monitor', 'Television', 'Speaker', 'Camera', 'Drone', 'Router',
        'Pro', 'Max', 'Plus', 'Mini', 'Lite', 'Ultra', 'Prime',
    ];

    /** What a shop actually pays out for. */
    private const COSTS = [
        'Rent', 'Electricity', 'Generator', 'Internet', 'Water', 'Salary', 'Wages',
        'Transport', 'Fuel', 'Diesel', 'Petrol', 'Maintenance', 'Repair', 'Cleaning',
        'Tea', 'Coffee', 'Lunch', 'Stationery', 'Printing', 'Advertising', 'Delivery',
        'Shipping', 'Customs', 'Tax', 'Bank', 'Fees', 'Phone', 'Subscription', 'Software',
        'Licence', 'Insurance', 'Furniture', 'Equipment', 'Tools', 'Packaging', 'Receipt',
        'Paper', 'Ink', 'Municipality', 'Guard', 'Security', 'Sign', 'Decoration',
    ];
}
