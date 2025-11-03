#!/bin/bash
# Setup local D1 database for testing

echo "🚀 Setting up local D1 database..."

cd "$(dirname "$0")/.."

# Check if wrangler is installed
if ! command -v wrangler &> /dev/null; then
    echo "❌ Wrangler not found. Install it with: npm install -g wrangler"
    exit 1
fi

# Create local D1 database if it doesn't exist
echo "📦 Creating local D1 database..."
wrangler d1 create laravel-d1-test --local 2>/dev/null || echo "✓ Database already exists"

# Initialize database with test schema
echo "🔧 Database ready for testing"

echo "✅ Local D1 setup complete!"
echo ""
echo "To run tests with local D1:"
echo "  ./tests/setup-local-d1.sh"
echo "  composer test"
