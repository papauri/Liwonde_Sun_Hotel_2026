#!/bin/bash
# Hotel Website - Cron Job Setup Script
# This script sets up all required cron jobs for production

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Hotel Website - Cron Setup${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Detect current directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

echo -e "${YELLOW}Project Directory:${NC} $PROJECT_DIR"
echo ""

# Detect PHP path
PHP_PATH=$(which php)
if [ -z "$PHP_PATH" ]; then
    echo -e "${RED}ERROR: PHP not found in PATH${NC}"
    echo "Please install PHP or update your PATH"
    exit 1
fi
echo -e "${YELLOW}PHP Path:${NC} $PHP_PATH"
echo ""

# Check if scripts exist
if [ ! -f "$PROJECT_DIR/scripts/check-tentative-bookings.php" ]; then
    echo -e "${RED}ERROR: Tentative bookings script not found${NC}"
    exit 1
fi

if [ ! -f "$PROJECT_DIR/scripts/scheduled-cache-clear.php" ]; then
    echo -e "${RED}ERROR: Cache clear script not found${NC}"
    exit 1
fi

echo -e "${GREEN}✓ All scripts found${NC}"
echo ""

# Create logs directory if it doesn't exist
if [ ! -d "$PROJECT_DIR/logs" ]; then
    mkdir -p "$PROJECT_DIR/logs"
    chmod 755 "$PROJECT_DIR/logs"
    echo -e "${GREEN}✓ Created logs directory${NC}"
else
    echo -e "${GREEN}✓ Logs directory exists${NC}"
fi
echo ""

# Generate crontab content
CRONTAB_FILE="/tmp/hotel-crontab.txt"

cat > "$CRONTAB_FILE" << EOF
# Hotel Website - Production Cron Jobs
# Generated: $(date)
# Project: $PROJECT_DIR

# Check and expire tentative/pending bookings every hour
0 * * * * $PHP_PATH $PROJECT_DIR/scripts/check-tentative-bookings.php >> /dev/null 2>&1

# Clear cache daily at 3 AM (if scheduled clearing enabled in admin panel)
0 3 * * * $PHP_PATH $PROJECT_DIR/scripts/scheduled-cache-clear.php >> /dev/null 2>&1

# Generate sitemap weekly on Sundays at 2 AM (if generate-sitemap.php exists)
0 2 * * 0 $PHP_PATH $PROJECT_DIR/generate-sitemap.php >> /dev/null 2>&1
EOF

echo -e "${GREEN}Crontab configuration generated${NC}"
echo ""
echo -e "${YELLOW}Contents:${NC}"
echo "----------------------------------------"
cat "$CRONTAB_FILE"
echo "----------------------------------------"
echo ""

# Ask user for installation method
echo -e "${YELLOW}Choose installation method:${NC}"
echo "1) Add to current user's crontab"
echo "2) Create system-wide cron file (requires sudo)"
echo "3) Just save to file and exit (manual installation)"
echo ""
read -p "Enter choice [1-3]: " choice

case $choice in
    1)
        # Backup existing crontab
        BACKUP_FILE="/tmp/hotel-crontab-backup-$(date +%Y%m%d-%H%M%S).txt"
        crontab -l > "$BACKUP_FILE" 2>/dev/null
        if [ $? -eq 0 ]; then
            echo -e "${GREEN}✓ Backed up existing crontab to $BACKUP_FILE${NC}"
        fi
        
        # Add new crons
        (crontab -l 2>/dev/null; echo ""; cat "$CRONTAB_FILE") | crontab -
        if [ $? -eq 0 ]; then
            echo -e "${GREEN}✓ Cron jobs added to user crontab${NC}"
        else
            echo -e "${RED}ERROR: Failed to update crontab${NC}"
            exit 1
        fi
        ;;
        
    2)
        SYSTEM_CRON="/etc/cron.d/hotel-website"
        echo ""
        echo -e "${YELLOW}This will create: $SYSTEM_CRON${NC}"
        echo "This requires sudo privileges"
        echo ""
        read -p "Continue? [y/N]: " confirm
        if [ "$confirm" == "y" ] || [ "$confirm" == "Y" ]; then
            sudo cp "$CRONTAB_FILE" "$SYSTEM_CRON"
            sudo chmod 644 "$SYSTEM_CRON"
            echo -e "${GREEN}✓ System cron file created${NC}"
        else
            echo "Cancelled"
            exit 0
        fi
        ;;
        
    3)
        SAVE_FILE="$PROJECT_DIR/crontab.txt"
        cp "$CRONTAB_FILE" "$SAVE_FILE"
        echo -e "${GREEN}✓ Saved to: $SAVE_FILE${NC}"
        echo ""
        echo "To install manually, run:"
        echo "  crontab $SAVE_FILE"
        ;;
        
    *)
        echo -e "${RED}Invalid choice${NC}"
        exit 1
        ;;
esac

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Setup Complete!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "Next steps:"
echo "1. Verify cron jobs: crontab -l"
echo "2. Test scripts manually:"
echo "   $PHP_PATH $PROJECT_DIR/scripts/check-tentative-bookings.php"
echo "3. Monitor logs:"
echo "   tail -f $PROJECT_DIR/logs/tentative-bookings-cron.log"
echo ""

# Cleanup
rm "$CRONTAB_FILE"
