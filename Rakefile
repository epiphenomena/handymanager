task :default => :sync

task :sync do
    sh "rsync -avzzh --progress --delete --exclude .git --exclude Rakefile --exclude handymanager.db ./ timlawles@dream:handy.timlawles.com/"
end