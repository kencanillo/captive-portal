<?php

namespace Database\Seeders;

use App\Models\KnowledgeBaseArticle;
use App\Models\User;
use Illuminate\Database\Seeder;

class KnowledgeBaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@example.com')->first() ?? User::first();

        $articles = [
            [
                'title' => 'Operator Credential Management Guide',
                'slug' => 'operator-credential-management-guide',
                'excerpt' => 'Complete guide to managing operator credentials with automatic Omada controller integration.',
                'content' => $this->getOperatorCredentialGuide(),
                'category' => 'operators',
                'tags' => ['credentials', 'omada', 'automation', 'security'],
                'is_published' => true,
                'is_featured' => true,
                'sort_order' => 1,
            ],
            [
                'title' => 'Session Expiration and Deauthorization',
                'slug' => 'session-expiration-deauthorization',
                'excerpt' => 'Understanding how WiFi sessions expire and clients are deauthorized from the Omada controller.',
                'content' => $this->getSessionExpirationGuide(),
                'category' => 'sessions',
                'tags' => ['sessions', 'deauthorization', 'omada', 'automation'],
                'is_published' => true,
                'is_featured' => false,
                'sort_order' => 2,
            ],
            [
                'title' => 'Service Fee Configuration',
                'slug' => 'service-fee-configuration',
                'excerpt' => 'How to configure flexible service fees for operators including site-wide, operator-specific, and revenue-tiered fees.',
                'content' => $this->getServiceFeeGuide(),
                'category' => 'payments',
                'tags' => ['service-fees', 'operators', 'revenue', 'configuration'],
                'is_published' => true,
                'is_featured' => false,
                'sort_order' => 3,
            ],
            [
                'title' => 'Troubleshooting: Client Still Connected After Session Expiry',
                'slug' => 'troubleshooting-client-still-connected',
                'excerpt' => 'Step-by-step troubleshooting guide for when clients remain connected after WiFi sessions expire.',
                'content' => $this->getTroubleshootingGuide(),
                'category' => 'troubleshooting',
                'tags' => ['troubleshooting', 'sessions', 'omada', 'connectivity'],
                'is_published' => true,
                'is_featured' => true,
                'sort_order' => 4,
            ],
            [
                'title' => 'Payment Processing Workflow',
                'slug' => 'payment-processing-workflow',
                'excerpt' => 'Complete overview of how payments are processed from portal checkout to webhook confirmation.',
                'content' => $this->getPaymentWorkflowGuide(),
                'category' => 'payments',
                'tags' => ['payments', 'paymongo', 'webhooks', 'workflow'],
                'is_published' => true,
                'is_featured' => false,
                'sort_order' => 5,
            ],
            [
                'title' => 'Site and Access Point Management',
                'slug' => 'site-access-point-management',
                'excerpt' => 'Managing sites and access points in the CaptivePortal system with Omada controller integration.',
                'content' => $this->getSiteManagementGuide(),
                'category' => 'sites',
                'tags' => ['sites', 'access-points', 'omada', 'management'],
                'is_published' => true,
                'is_featured' => false,
                'sort_order' => 6,
            ],
        ];

        foreach ($articles as $articleData) {
            KnowledgeBaseArticle::create(array_merge($articleData, [
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]));
        }
    }

    private function getOperatorCredentialGuide(): string
    {
        return <<<MARKDOWN
# Operator Credential Management Guide

This guide covers the complete operator credential management system in CaptivePortal, including automatic Omada controller integration.

## Overview

The operator credential management system provides:
- **Automatic credential generation** following naming conventions
- **Omada controller integration** for account creation and management
- **Validation and synchronization** between database and controller
- **Security enforcement** to prevent manual overrides

## Naming Convention

All operator credentials follow this pattern:
```
{operator_name}_operator@{current_year}Lab
```

Examples:
- `bruckelab_operator@2026Lab`
- `depthflow_operator@2026Lab`
- `techflowinc_operator@2026Lab`

## Automatic Management

### Creating New Operators

1. **Register Operator** in admin panel
2. **Run Sync Command**:
   ```bash
   php artisan app:sync-operator-credentials
   ```
3. **System Automatically**:
   - Generates proper credentials
   - Creates account in Omada controller
   - Assigns site permissions
   - Validates everything works

### Command Options

```bash
# Validate only (no changes)
php artisan app:sync-operator-credentials --validate-only

# Database sync only (no Omada)
php artisan app:sync-operator-credentials --no-omada

# Full sync including Omada controller
php artisan app:sync-operator-credentials
```

## Manual Override Prevention

The system prevents manual credential changes that violate naming conventions:

```
Error: Username 'wrong_name' does not follow naming convention
Expected: bruckelab_operator
Use "php artisan app:sync-operator-credentials" to auto-fix this
```

## Omada Controller Integration

### What Gets Synced
- Operator accounts with proper credentials
- Site permissions for assigned sites
- Role assignments (hotspot management)

### Validation Features
- Checks if operator exists in Omada
- Validates credentials against controller
- Updates permissions when sites change

## Troubleshooting

### Common Issues

1. **"Invalid username or password"**
   - Run: `php artisan app:sync-operator-credentials`
   - Check Omada controller connectivity

2. **"Operator has no permissions"**
   - Verify site assignments
   - Check Omada controller permissions

3. **"Naming convention violation"**
   - Use sync command to auto-fix
   - Don't manually override credentials

## Best Practices

1. **Always use the sync command** for credential management
2. **Let the system generate credentials** automatically
3. **Validate after changes** with `--validate-only`
4. **Monitor logs** for sync issues
5. **Keep Omada controller accessible** for API calls

## Security Considerations

- Credentials follow consistent patterns
- Manual overrides are prevented
- All changes are logged
- Omada controller permissions are limited to necessary sites
MARKDOWN;
    }

    private function getSessionExpirationGuide(): string
    {
        return <<<MARKDOWN
# Session Expiration and Deauthorization

Understanding how WiFi sessions expire and clients are properly deauthorized from the Omada controller.

## Session Lifecycle

1. **Client connects** to captive portal
2. **Session created** with end time
3. **Payment processed** and session activated
4. **Session expires** when end time reached
5. **Client deauthorized** from Omada controller

## Automatic Expiration

The system runs every 5 minutes to expire sessions:

```bash
# Scheduler command (runs automatically)
php artisan wifi:expire-sessions
```

### What Happens During Expiration

1. **Find expired sessions** (end_time < now)
2. **Load operator credentials** for the site
3. **Call Omada API** to deauthorize client
4. **Update session status** to 'expired'
5. **Log results** for troubleshooting

## Operator-Specific Deauthorization

### Credential Priority
1. **Operator credentials** (if available)
2. **Default credentials** (fallback)

### Site Resolution
System uses the correct Omada site ID:
```php
// Uses database omada_site_id, not site name
\$siteId = \$session->site->omada_site_id; // e.g., "69e31ca32109e3181bab7109"
```

## Common Issues

### "Client Still Connected After Expiry"

**Symptoms:**
- Session shows as expired in database
- Client still has internet access
- No deauthorization in logs

**Causes:**
1. **Scheduler not running**
2. **Wrong site identifier** used
3. **Operator credentials** lack permissions
4. **Omada controller** unreachable

**Solutions:**
1. **Start scheduler**: `php artisan schedule:work`
2. **Fix credentials**: `php artisan app:sync-operator-credentials`
3. **Check site IDs** in database
4. **Verify Omada connectivity**

### Manual Deauthorization

For immediate fixes:

```php
// Force deauthorization of specific session
\$session = WifiSession::find(SESSION_ID);
\$wifiSessionService = app(WifiSessionService::class);
\$wifiSessionService->expireSession(\$session);
```

## Configuration

### Scheduler Setup

Add to crontab for automatic expiration:
```bash
* * * * * cd /path/to/CaptivePortal && php artisan schedule:run >> /dev/null 2>&1
```

### Site Mapping

Ensure proper site mapping:
- `sites.omada_site_id` matches Omada controller
- `sites.operator_id` assigned to correct operator
- `operators.credentials` configured properly

## Monitoring

### Log Messages

Watch for these log entries:
```
Successfully deauthorized WiFi session using operator credentials
Failed to deauthorize with operator credentials, falling back to default
Failed to deauthorize expired WiFi session with all credential types
```

### Status Indicators

- **Active**: Session is valid and authorized
- **Expired**: Session ended and client deauthorized
- **Release Failed**: Deauthorization failed (needs attention)

## Best Practices

1. **Keep scheduler running** continuously
2. **Monitor logs** for deauthorization failures
3. **Validate operator credentials** regularly
4. **Check site mappings** after changes
5. **Test manual deauthorization** when issues occur
MARKDOWN;
    }

    private function getServiceFeeGuide(): string
    {
        return <<<MARKDOWN
# Service Fee Configuration

Complete guide to configuring flexible service fees for operators with multiple pricing models.

## Overview

The service fee system supports three fee types with automatic priority resolution:

1. **Operator-specific fees** (highest priority)
2. **Revenue-tiered fees** (medium priority)  
3. **Site-wide default fees** (lowest priority)

## Fee Types

### 1. Operator-Specific Fees

Apply to a specific operator regardless of revenue:
- **Scope**: Single operator
- **Priority**: 1 (highest)
- **Use case**: Special arrangements with operators

### 2. Revenue-Tiered Fees

Apply based on operator's revenue range:
- **Scope**: Revenue-based tiers
- **Priority**: 2 (medium)
- **Use case**: Volume-based pricing

### 3. Site-Wide Default Fees

Apply to all operators when no other fee applies:
- **Scope**: All operators
- **Priority**: 3 (lowest)
- **Use case**: Base fee structure

## Configuration

### Admin Panel Access

Navigate to: **Admin > Service Fees**

### Creating Fee Settings

1. **Click "Create Service Fee"**
2. **Select Fee Type**:
   - Site-wide default
   - Operator-specific  
   - Revenue tier
3. **Configure Settings** based on type
4. **Set Fee Rate** (decimal, e.g., 0.05 for 5%)
5. **Add Description** (optional)

### Revenue Tier Configuration

For revenue tiers, specify:
- **Minimum Revenue**: Lower bound (inclusive)
- **Maximum Revenue**: Upper bound (inclusive)
- **Fee Rate**: Percentage for this tier

**Example Tiers:**
- Tier 1: $0 - $1,000 @ 5%
- Tier 2: $1,001 - $5,000 @ 4%
- Tier 3: $5,001+ @ 3%

## Priority Resolution

How fees are calculated:

```php
// Priority 1: Operator-specific
\$operatorFee = ServiceFeeSetting::operatorSpecific()->forOperator(\$operator)->first();

// Priority 2: Revenue tier  
\$revenueTier = ServiceFeeSetting::revenueTier()->forRevenue(\$operatorRevenue)->first();

// Priority 3: Site-wide default
\$siteWide = ServiceFeeSetting::siteWide()->first();

// Final fallback: Config default
return config('portal.ewallet_fee_rate', 0.05);
```

## Validation Rules

### Revenue Tier Overlap Prevention

System prevents overlapping revenue ranges:
- **No gaps** between tiers
- **No overlaps** between tiers
- **Complete coverage** from $0 to infinity

### Active Fee Management

Only **active** fees are considered:
- Multiple fees can exist
- Only one active fee per priority level
- Inactive fees are ignored

## Usage in Payouts

Service fees are automatically calculated during:

1. **Payout requests** creation
2. **Revenue summaries** generation
3. **Operator statements** production

### Calculation Example

```
Operator Revenue: $2,500
Available Fees:
- Operator-specific: None
- Revenue tier: 4% ($1,001-$5,000)
- Site-wide: 5%

Applied Fee: 4% (revenue tier)
Service Fee: $100
Net Payout: $2,400
```

## Management Operations

### Activate/Deactivate Fees

Toggle fee status without deletion:
- **Activate**: Makes fee available for calculation
- **Deactivate**: Excludes fee from calculations

### Fee Updates

Modify existing fees:
- **Rate changes** affect future calculations
- **Tier changes** require validation
- **Historical data** remains unchanged

## Best Practices

1. **Plan fee structure** before implementation
2. **Test calculations** with sample data
3. **Monitor revenue impacts** after changes
4. **Document fee logic** for operators
5. **Review quarterly** for optimization

## Troubleshooting

### Common Issues

1. **Wrong fee applied**
   - Check priority order
   - Verify active status
   - Validate revenue ranges

2. **No fee calculated**
   - Ensure at least one active fee exists
   - Check revenue range coverage
   - Verify operator assignments

3. **Unexpected fee amounts**
   - Review calculation logic
   - Check for overlapping tiers
   - Validate decimal precision
MARKDOWN;
    }

    private function getTroubleshootingGuide(): string
    {
        return <<<MARKDOWN
# Troubleshooting: Client Still Connected After Session Expiry

Step-by-step guide for resolving issues where clients remain connected to the internet after their WiFi sessions expire.

## Quick Diagnosis

### Check Session Status
```bash
php artisan tinker
\$session = WifiSession::find(SESSION_ID);
echo 'Status: ' . \$session->session_status . PHP_EOL;
echo 'Active: ' . (\$session->is_active ? 'YES' : 'NO') . PHP_EOL;
echo 'End Time: ' . \$session->end_time?->toDateTimeString() . PHP_EOL;
```

### Check Recent Logs
```bash
tail -20 storage/logs/laravel.log | grep -i "deauthorize\|expire"
```

## Common Causes and Solutions

### 1. Scheduler Not Running

**Symptoms:**
- Sessions expired in database but not deauthorized
- No recent deauthorization logs

**Solution:**
```bash
# Start scheduler
php artisan schedule:work

# Or set up cron job
crontab -e
# Add: * * * * * cd /path/to/CaptivePortal && php artisan schedule:run >> /dev/null 2>&1
```

### 2. Wrong Site Identifier

**Symptoms:**
- "The current user does not have permissions to access this site"
- Deauthorization attempts fail with permission errors

**Solution:**
```bash
# Check site mapping
php artisan tinker
\$session = WifiSession::find(SESSION_ID);
echo 'Site Name: ' . \$session->site->name . PHP_EOL;
echo 'Omada Site ID: ' . \$session->site->omada_site_id . PHP_EOL;

# Fix: Update database with correct omada_site_id
\$session->site->update(['omada_site_id' => 'CORRECT_ID']);
```

### 3. Operator Credentials Issues

**Symptoms:**
- "Invalid username or password" errors
- Deauthorization falls back to default credentials

**Solution:**
```bash
# Sync operator credentials
php artisan app:sync-operator-credentials

# Verify credentials
php artisan tinker
\$operator = \$session->site->operator;
\$creds = \$operator->credentials()->first();
echo 'Username: ' . \$creds->hotspot_operator_username . PHP_EOL;
```

### 4. Omada Controller Connectivity

**Symptoms:**
- Network timeout errors
- API request failures

**Solution:**
```bash
# Test connectivity
curl -k https://OMADA_CONTROLLER:8043/api/info

# Check controller settings
php artisan tinker
\$settings = ControllerSetting::first();
echo 'Base URL: ' . \$settings->base_url . PHP_EOL;
echo 'Username: ' . \$settings->username . PHP_EOL;
```

## Manual Deauthorization

For immediate client disconnection:

```bash
php artisan tinker
\$session = WifiSession::find(SESSION_ID);
\$wifiSessionService = app(WifiSessionService::class);

# Force deauthorization
\$session->forceFill(['is_active' => true])->save();
\$wifiSessionService->expireSession(\$session);
\$session->forceFill(['is_active' => false, 'session_status' => 'expired'])->save();

echo 'Client should lose internet access within 30 seconds' . PHP_EOL;
```

## Verification Steps

### 1. Check Scheduler Status
```bash
# Verify scheduler is running
ps aux | grep "schedule:work"

# Check recent runs
grep "wifi:expire-sessions" storage/logs/laravel.log | tail -5
```

### 2. Validate Session Data
```bash
php artisan tinker
\$session = WifiSession::find(SESSION_ID);
echo 'MAC: ' . \$session->mac_address . PHP_EOL;
echo 'Site: ' . \$session->site->name . PHP_EOL;
echo 'Operator: ' . \$session->site->operator->business_name . PHP_EOL;
```

### 3. Test Omada API
```bash
php artisan tinker
\$omadaService = app(OmadaService::class);
\$settings = ControllerSetting::first();
\$openApi = \$omadaService->openApiAuthenticatedClient(\$omadaService->normalizeSettings(\$settings));
echo 'Omada API: SUCCESS' . PHP_EOL;
```

## Prevention Measures

### 1. Regular Maintenance
```bash
# Weekly credential validation
php artisan app:sync-operator-credentials --validate-only

# Check scheduler status
php artisan schedule:list
```

### 2. Monitoring Setup
Monitor these log patterns:
- `Successfully deauthorized WiFi session`
- `Failed to deauthorize with operator credentials`
- `The current user does not have permissions`

### 3. Health Checks
Create automated checks for:
- Scheduler process status
- Omada controller connectivity
- Operator credential validity
- Site mapping accuracy

## Escalation Criteria

Contact support if:
- Multiple clients remain connected after expiry
- Scheduler stops working repeatedly
- Omada API becomes inaccessible
- Manual deauthorization fails

## Documentation

Document each incident with:
- Session ID and MAC address
- Error messages from logs
- Steps taken to resolve
- Time to resolution
- Root cause analysis
MARKDOWN;
    }

    private function getPaymentWorkflowGuide(): string
    {
        return <<<MARKDOWN
# Payment Processing Workflow

Complete overview of how payments are processed from portal checkout to webhook confirmation in the CaptivePortal system.

## Payment Flow Overview

```
Client Portal -> Payment Creation -> PayMongo -> Webhook -> Session Activation
```

## Step-by-Step Process

### 1. Portal Checkout

**Client Action:**
- Selects plan on captive portal
- Clicks "Activate Instantly" or payment method
- Enters payment details

**System Response:**
- Creates payment session token
- Calls PayMongo API for payment creation
- Returns payment details to client

### 2. Payment Creation

**Controller:** `PaymentController@create`

**Process:**
```php
// Resolve session from token
\$session = \$portalTokenService->resolveSessionToken(\$token);

// Create or reuse payment
\$payment = config('portal.bypass_payment')
    ? \$payMongoQrPhService->createBypassedPayment(\$session)
    : \$payMongoQrPhService->createOrReusePayment(\$session);
```

**Payment States:**
- `pending` - Awaiting payment
- `paid` - Payment confirmed
- `failed` - Payment failed
- `expired` - Payment expired

### 3. PayMongo Integration

**API Endpoints:**
- Create payment: `POST /v1/sources`
- Check status: `GET /v1/sources/{id}`
- Webhook: `POST /webhooks`

**Payment Methods:**
- QR Code (QRPH)
- Credit Card
- E-Wallet
- Bank Transfer

### 4. Webhook Processing

**Controller:** `PayMongoWebhookController@handle`

**Events Handled:**
- `source.chargeable` - Payment ready
- `payment.paid` - Payment successful
- `payment.failed` - Payment failed
- `payment.updated` - Status changes

**Webhook Validation:**
```php
// Verify webhook signature
\$signature = \$request->header('Paymongo-Signature');
\$payload = \$request->getContent();
\$computedSignature = hash_hmac('sha256', \$payload, \$webhookSecret);

if (!hash_equals(\$signature, \$computedSignature)) {
    abort(403, 'Invalid webhook signature');
}
```

### 5. Session Activation

**Trigger:** Payment `paid` webhook received

**Process:**
```php
// Activate WiFi session
\$wifiSessionService =activateSession(\$payment->wifiSession);

// Authorize client in Omada
\$omadaService->authorizeClient(\$settings, \$session, \$operatorCredentials);
```

**Result:**
- Session marked as `active`
- Client gains internet access
- Session end time set

## Payment Types

### 1. Standard Payment

**Flow:**
1. Client selects plan
2. PayMongo payment created
3. Client completes payment
4. Webhook confirms payment
5. Session activated

**Features:**
- Real-time payment processing
- Multiple payment methods
- Automatic session activation
- Webhook reliability

### 2. Bypass Payment

**Flow:**
1. Client selects plan
2. System creates bypass payment
3. Session immediately activated
4. No actual payment processed

**Use Cases:**
- Testing and development
- Free promotional periods
- Admin overrides
- Emergency access

**Configuration:**
```env
PORTAL_BYPASS_PAYMENT=true
```

## Error Handling

### Common Payment Errors

1. **"Invalid username or password"**
   - Operator credentials issue
   - Run: `php artisan app:sync-operator-credentials`

2. **"Payment creation failed"**
   - PayMongo API issue
   - Check API keys and connectivity

3. **"Webhook verification failed"**
   - Webhook secret mismatch
   - Update PayMongo webhook settings

### Retry Logic

**Payment Creation:**
- Automatic retry on network errors
- Exponential backoff
- Maximum 3 attempts

**Webhook Processing:**
- Idempotent processing
- Duplicate prevention
- Error logging and monitoring

## Configuration

### PayMongo Settings

```env
PAYMONGO_SECRET_KEY=sk_test_xxx
PAYMONGO_WEBHOOK_SECRET=whsec_xxx
PAYMONGO_BASE_URL=https://api.paymongo.com/v1
```

### Portal Settings

```env
PORTAL_BYPASS_PAYMENT=false
PORTAL_SESSION_TIMEOUT=30
```

## Monitoring

### Key Metrics

- **Payment success rate**
- **Webhook processing time**
- **Session activation latency**
- **Payment method distribution**

### Log Monitoring

Watch for these log patterns:
```
Payment creation failed
Webhook verification failed
Session activation failed
Omada authorization error
```

### Health Checks

Monitor:
- PayMongo API connectivity
- Webhook endpoint accessibility
- Database payment records
- Session activation success rate

## Security Considerations

### Webhook Security
- Signature verification required
- IP whitelist recommended
- HTTPS enforcement
- Rate limiting

### Payment Data
- Sensitive data encrypted
- PCI compliance considerations
- Audit trail maintenance
- Data retention policies

## Troubleshooting

### Payment Not Processing

1. **Check PayMongo API keys**
2. **Verify webhook configuration**
3. **Test payment creation manually**
4. **Review webhook logs**

### Session Not Activating

1. **Check payment status**
2. **Verify webhook delivery**
3. **Test session activation**
4. **Check Omada connectivity**

### Webhook Issues

1. **Verify webhook URL accessibility**
2. **Check signature validation**
3. **Test webhook delivery**
4. **Review PayMongo webhook logs**

## Best Practices

1. **Always validate webhook signatures**
2. **Implement proper error handling**
3. **Monitor payment success rates**
4. **Test all payment methods**
5. **Maintain webhook reliability**
6. **Document payment flows**
7. **Regular security audits**
MARKDOWN;
    }

    private function getSiteManagementGuide(): string
    {
        return <<<MARKDOWN
# Site and Access Point Management

Complete guide to managing sites and access points in the CaptivePortal system with Omada controller integration.

## Overview

The site management system provides:
- **Automatic site synchronization** with Omada controller
- **Access point monitoring** and status tracking
- **Operator assignment** and permission management
- **Site-specific configuration** and settings

## Site Structure

### Hierarchy
```
Operator (Business)
  L Site (Physical Location)
    L Access Points (WiFi Devices)
      L WiFi Sessions (Client Connections)
```

### Database Relationships
- `Operator` has many `Site`
- `Site` has many `AccessPoint`
- `Site` has many `WifiSession`
- `AccessPoint` has many `WifiSession`

## Site Management

### Creating Sites

**Automatic Creation:**
1. **Omada Sync**: Sites auto-created from Omada controller
2. **Command**: `php artisan omada:sync-access-points`
3. **Result**: New sites appear in admin panel

**Manual Creation:**
1. Navigate to **Admin > Access Points**
2. Click **"Sync Sites"**
3. Sites created from Omada controller data

### Site Configuration

**Required Fields:**
- **Name**: Site display name
- **Slug**: URL-friendly identifier
- **Omada Site ID**: Controller site identifier
- **Operator ID**: Assigned operator

**Optional Fields:**
- **Description**: Site details
- **Location**: Physical address
- **Contact**: Site contact information

### Operator Assignment

**Process:**
1. **Select Operator** from dropdown
2. **Assign Sites** to operator
3. **Save Changes**
4. **Credentials Synced** automatically

**Permission Effects:**
- Operator can only manage assigned sites
- Sessions use operator credentials
- Reports filtered by operator sites

## Access Point Management

### Synchronization

**Command:**
```bash
php artisan omada:sync-access-points
```

**What Gets Synced:**
- Access point names and MAC addresses
- Site assignments
- Online/offline status
- Configuration details

### Access Point Status

**Status Types:**
- **Online**: Access point reachable
- **Offline**: Access point not responding
- **Error**: Communication issues
- **Unknown**: Status not determined

### Manual Updates

**When to Sync Manually:**
- New access points added
- Configuration changes made
- Status issues detected
- Troubleshooting problems

## Omada Controller Integration

### Site Mapping

**Database Field:** `sites.omada_site_id`

**Example Mapping:**
```
Site Name: Juleanne_Operator
Omada ID: 69e31ca32109e3181bab7109
Operator: BruckeLab
```

### API Communication

**Endpoints Used:**
- `/openapi/v1/{controller_id}/sites` - List sites
- `/openapi/v1/{controller_id}/sites/{site_id}/devices` - List access points
- `/openapi/v1/{controller_id}/sites/{site_id}/clients` - Connected clients

### Authentication

**Methods:**
1. **Admin credentials** for full access
2. **Operator credentials** for limited access
3. **API client credentials** for programmatic access

## Troubleshooting

### Site Not Syncing

**Symptoms:**
- Sites not appearing in admin panel
- Access points showing old data
- Status not updating

**Solutions:**
```bash
# Force sync
php artisan omada:sync-access-points

# Check controller settings
php artisan tinker
\$settings = ControllerSetting::first();
echo 'Controller URL: ' . \$settings->base_url . PHP_EOL;

# Test API connectivity
curl -k \$settings->base_url/api/info
```

### Access Points Not Updating

**Symptoms:**
- Stale access point data
- Incorrect online/offline status
- Missing new access points

**Solutions:**
1. **Check Omada controller** connectivity
2. **Verify API credentials** are valid
3. **Run manual sync** command
4. **Check site assignments** in Omada

### Operator Assignment Issues

**Symptoms:**
- Sites not assigned to operators
- Wrong operator permissions
- Credential conflicts

**Solutions:**
```bash
# Sync operator credentials
php artisan app:sync-operator-credentials

# Check site assignments
php artisan tinker
\$sites = Site::with('operator')->get();
foreach (\$sites as \$site) {
    echo \$site->name . ' -> ' . (\$site->operator ? \$site->operator->business_name : 'None') . PHP_EOL;
}
```

## Best Practices

### Site Organization

1. **Logical naming** conventions
2. **Consistent operator** assignments
3. **Clear site descriptions**
4. **Proper location data**

### Access Point Management

1. **Regular synchronization** schedule
2. **Monitor status changes**
3. **Document configuration** changes
4. **Test connectivity** regularly

### Operator Management

1. **Assign appropriate sites**
2. **Validate credentials** regularly
3. **Monitor permission** issues
4. **Review access logs** periodically

## Configuration

### Scheduler Setup

Add to crontab for automatic sync:
```bash
# Sync access points every hour
0 * * * * cd /path/to/CaptivePortal && php artisan omada:sync-access-points >> /dev/null 2>&1
```

### Controller Settings

**Required Configuration:**
- Controller URL and port
- Admin credentials
- API client credentials
- Site access permissions

### Monitoring

**Log Monitoring:**
- Sync completion status
- API communication errors
- Permission issues
- Site assignment changes

**Health Checks:**
- Controller connectivity
- API response times
- Database consistency
- Operator credential validity

## Security Considerations

### Access Control

1. **Operator isolation** enforced
2. **Site-specific permissions**
3. **API credential protection**
4. **Audit trail maintenance**

### Data Protection

1. **Sensitive data encryption**
2. **Access logging**
3. **Regular credential rotation**
4. **Network security** measures

## Advanced Features

### Multi-Controller Support

Configure multiple Omada controllers:
```php
// Example configuration
'controllers' => [
    'primary' => [
        'url' => 'https://controller1.example.com',
        'credentials' => [...],
    ],
    'secondary' => [
        'url' => 'https://controller2.example.com',
        'credentials' => [...],
    ],
],
```

### Custom Site Attributes

Extend site model with additional fields:
- Physical location coordinates
- Contact information
- Equipment details
- Maintenance schedules

### Automated Workflows

Create automated processes:
- Site provisioning
- Access point configuration
- Operator onboarding
- Performance monitoring
MARKDOWN;
    }
}
