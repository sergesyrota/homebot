# -*- mode: ruby -*-
# vi: set ft=ruby :

$script = <<SCRIPT
# Update apt and get dependencies
apt-get update
apt-get install -y unzip curl wget vim mc python-software-properties php5-cli git php5-curl
# Install NodeJS repos, and then nodejs too.
curl -sL https://deb.nodesource.com/setup_7.x | sudo -E bash -
apt-get install nodejs
npm install claudia -g
curl -s "http://getcomposer.org/installer" | php -- --install-dir=/usr/bin --filename=composer
SCRIPT

Vagrant.configure(2) do |config|
  config.vm.box = "ubuntu/trusty64"
  config.vm.provision "shell", inline: $script, privileged: true
  config.vm.provider "virtualbox" do |v|
    v.memory = 2048
  end
end
