# MyLook Affiliate Product Picker

A professional WordPress plugin for affiliate marketers to display products from major affiliate networks like AWIN and TradeDoubler.

## Features

- **Smart Mapping**: Automatic field detection and mapping for different affiliate network feeds
- **Multiple Networks**: Support for AWIN, TradeDoubler, and more to come
- **Gutenberg Block**: Native WordPress block editor integration
- **Auto Affiliate Links**: Automatic generation of affiliate links with your credentials
- **Product Search**: Powerful search functionality across all configured feeds
- **Responsive Design**: Mobile-friendly product displays
- **Professional UI**: Clean, modern interface for both admin and frontend

## Installation

1. Upload the plugin files to `/wp-content/plugins/mylook-affiliate-product-picker/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure your affiliate network credentials in **MyLook Feeds > Network Setup**
4. Create your first feed in **MyLook Feeds**
5. Use the "MyLook Product Picker" block in your posts and pages

## Supported Networks

### Currently Supported
- **AWIN** (Affiliate Window)
- **TradeDoubler**

### Coming Soon
- Webgains
- Rakuten Advertising  
- CJ Affiliate
- Admitad
- Partnerize
- ShareASale
- Impact
- Sovrn
- Amazon (API)
- eBay (API)

## Configuration

### Network Credentials Setup

1. Go to **MyLook Feeds > Network Setup** in your WordPress admin
2. For each network, enter your credentials:

**AWIN:**
- Advertiser ID
- API Token
- Publisher ID

**TradeDoubler:**
- API Token
- Organization ID
- Program ID

3. Test your connection to ensure credentials are working
4. Save your configuration

### Feed Management

1. Go to **MyLook Feeds** in your WordPress admin
2. Click "Add New Feed"
3. Select your configured network
4. Enter a descriptive name for your feed
5. The plugin will automatically generate the feed URL and mapping
6. Save and test your feed

## Usage

### Adding Products to Posts/Pages

1. Edit your post or page in the WordPress editor
2. Add a new block and search for "MyLook Product Picker"
3. Enter search terms for products you want to display
4. Select products from the search results
5. Save your post - products will display automatically

### Product Display

Products are displayed as attractive cards showing:
- Product image
- Brand and title
- Current price (with strikethrough for discounts)
- Shipping information
- Merchant name
- Discount badges for sales

## Technical Details

### Smart Mapping

The plugin automatically detects and maps fields from different affiliate networks:

- **Product ID** → Various ID fields
- **Title** → Product names and titles  
- **Price** → Current/sale prices
- **Images** → Product images
- **Affiliate Links** → Deep links with your tracking
- **Brand** → Brand/manufacturer information
- **Attributes** → Size, color, material, etc.

### Caching

- Product data is cached for 24 hours to improve performance
- Feed indexes are rebuilt daily automatically
- Manual rebuild available in admin interface

### Security

- All affiliate network credentials are encrypted in the database
- WordPress nonce verification on all admin actions
- Proper sanitization of all user inputs
- Secure API endpoint access

## Developer Information

### File Structure

```
mylook-affiliate-product-picker/
├── mylook-affiliate-product-picker.php    # Main plugin file
├── includes/
│   ├── class-feed-manager.php             # Feed management
│   ├── class-product-picker.php           # Gutenberg block
│   ├── class-smart-mapper.php             # Field mapping
│   └── class-network-handlers.php         # Network integrations
├── assets/                                # CSS and images
├── build/                                 # JavaScript build files
└── README.md
```

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- Active affiliate network accounts with API access

## Changelog

### Version 3.0.0
- Complete rewrite and unification of Feed Manager and Product Picker
- Added smart mapping functionality
- Support for AWIN and TradeDoubler networks
- New credential management system
- Improved admin interface
- Enhanced security features
- Mobile-responsive design

## License

Commercial License - This plugin is intended for commercial use.

---

**MyLook Affiliate Product Picker** - Professional affiliate marketing made easy.
