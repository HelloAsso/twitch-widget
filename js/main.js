function displayAlertBox(pseudo, message, amount) {
    var container = document.querySelector('.widget-alert-box');
    var oldContent = container.innerHTML;
    container.innerHTML = '';

    // Afficher l'image
    var img = document.createElement('img');
    img.src = window.image;
    img.style.maxWidth = '100%';
    img.classList.add('fade');
    container.appendChild(img);

    // Afficher le message template
    let eur = new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'EUR',
    });
    var messageTemplate = document.createElement('p');
    messageTemplate.innerHTML = window.message_template.replace("{pseudo}", pseudo).replace("{message}", message).replace("{amount}", eur.format(amount / 100));
    messageTemplate.style.marginTop = '10px';
    messageTemplate.classList.add('fade');
    container.appendChild(messageTemplate);

    // Appliquer l'effet de fondu
    setTimeout(function () {
        img.classList.add('show');
        messageTemplate.classList.add('show');
    }, 100); // Délai pour déclencher la transition

    // Jouer le son
    var audio = new Audio(window.sound);
    audio.volume = window.sound_volume;
    audio.play();

    // Retirer l'image et le message après 3 secondes avec un effet de fondu
    setTimeout(function () {
        img.classList.remove('show');
        messageTemplate.classList.remove('show');
        audio.pause();
        audio.currentTime = 0;

        setTimeout(function () {
            container.innerHTML = oldContent;
        }, 1000); // Attendre que le fondu soit terminé avant de vider le conteneur
    }, window.alert_duration);
}

function updateDonationBar() {
    const currentAmountUnit = window.currentAmount / 100;
    const percentage = Math.min(100, (currentAmountUnit / window.goalAmount) * 100);
    document.getElementById('goal-current').textContent = currentAmountUnit + ' €';
    document.getElementById('total-bar').style.width = percentage + '%';
}

function fetchDonation() {
    const request = new XMLHttpRequest()
    request.open("GET", 'fetch_donations.php?charityStreamId=' + window.charityStreamId + 
        (window.continuationToken ? ('&continuationToken=' + window.continuationToken) : '') + 
        (window.currentAmount ? ('&currentAmount=' + window.currentAmount) : '') + 
        (window.from ? ('&from=' + window.from) : ''), true)
    request.onload = () => {
        if (request.status === 200) {
            const json = JSON.parse(request.response);
            if (document.querySelector('.widget-donation-goal'))
                updateDonationBar();
            if (document.querySelector('.widget-alert-box') && json.donations && json.donations.length > 0)
                json.donations.forEach(donation => {
                    displayAlertBox(donation.pseudo, donation.message, donation.amount);
                });

            window.currentAmount = json.amount;
            window.continuationToken = json.continuationToken;
        }
        else {
            console.error('Erreur lors de la récupération des données de donation:', request.response);
        }
    }

    request.send()
}