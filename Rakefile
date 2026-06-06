REMOTE = "timlawles@dream:handy.timlawles.com"

task :default => :sync

# Deploy to production. Never touches the production database or config:
#  - *.db is excluded so the production data is never overwritten
#  - config.json is excluded so production tokens are never overwritten
task :sync do
    sh "rsync -avzzh --progress --delete --exclude .git --exclude Rakefile --exclude tools --exclude '*.db' --exclude config.json ./ #{REMOTE}/"
end

# Pull a copy of the production database for local development/testing.
# Backs up any existing local copy first.
task :pulldb do
    if File.exist?("handymanager.db")
        backup = "handymanager.db.backup-#{Time.now.strftime('%Y%m%d-%H%M%S')}"
        cp "handymanager.db", backup
        puts "Backed up local db to #{backup}"
    end
    sh "rsync -avzh #{REMOTE}/handymanager.db ./handymanager.db"
    puts "Production database copied to ./handymanager.db (local only - sync never pushes it back)"
end

# Seed a fresh local test database (handymanager-test.db) with sample data
task :seed do
    sh "HANDYMANAGER_DB=handymanager-test.db php tools/seed.php"
end

# Run the dev server against the seeded test database
task :dev do
    sh "HANDYMANAGER_DB=handymanager-test.db php dev-server.php"
end

# Run the API smoke tests against a throwaway database
task :test do
    sh "bash tools/smoke-test.sh"
end
