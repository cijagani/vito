CODENAME=$(lsb_release -cs)
if curl -fsIL --retry 3 "https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/${CODENAME}/Release" > /dev/null; then
    sudo add-apt-repository ppa:ondrej/php -y
elif curl -fsIL --retry 3 "https://packages.sury.org/php/dists/${CODENAME}/Release" > /dev/null; then
    if ! sudo curl -fsSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb || ! sudo dpkg -i /tmp/debsuryorg-archive-keyring.deb; then
        echo 'VITO_SSH_ERROR: failed to install the packages.sury.org keyring' && exit 1
    fi
    sudo rm -f /tmp/debsuryorg-archive-keyring.deb
    echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ ${CODENAME} main" | sudo tee /etc/apt/sources.list.d/php.list
fi

sudo DEBIAN_FRONTEND=noninteractive apt-get update -o Acquire::AllowReleaseInfoChange::Label=true

if ! apt-cache show php{{ $version }}-fpm > /dev/null 2>&1; then
    echo 'VITO_SSH_ERROR: PHP {{ $version }} is not available for this Ubuntu release' && exit 1
fi

if ! sudo DEBIAN_FRONTEND=noninteractive apt-get install -y php{{ $version }} php{{ $version }}-fpm php{{ $version }}-mbstring php{{ $version }}-mysql php{{ $version }}-gd php{{ $version }}-xml php{{ $version }}-curl php{{ $version }}-gettext php{{ $version }}-zip php{{ $version }}-bcmath php{{ $version }}-soap php{{ $version }}-redis php{{ $version }}-sqlite3 php{{ $version }}-tokenizer php{{ $version }}-pgsql php{{ $version }}-pdo php{{ $version }}-intl; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

if ! sudo sed -i 's/www-data/{{ $user }}/g' /etc/php/{{ $version }}/fpm/pool.d/www.conf; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

sudo systemctl enable php{{ $version }}-fpm

sudo systemctl start php{{ $version }}-fpm
