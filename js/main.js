var alertQueue = [];
var isAlertActive = false;

function displayAlertBox(pseudo, message, amount) {
    alertQueue.push({ pseudo, message, amount });

    if (!isAlertActive) {
        processAlertQueue();
    }
}

function processAlertQueue() {
    if (alertQueue.length === 0) {
        isAlertActive = false;
        return;
    }

    isAlertActive = true;
    const alert = alertQueue.shift();
    const { pseudo, message, amount } = alert;
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
    var audio = new Audio(window.sound);

    // Wait to hide display between alert
    setTimeout(function () {
        img.classList.add('show');
        messageTemplate.classList.add('show');
        
        audio.volume = window.sound_volume;
        audio.play();
    }, 100)

    setTimeout(function () {
        img.classList.remove('show');
        messageTemplate.classList.remove('show');
        audio.pause();
        audio.currentTime = 0;

        container.innerHTML = oldContent;
        processAlertQueue();
    }, window.alert_duration);
}

const options = {
    separator: ' ',
    separator: ' ',
    decimal: ',',
    suffix: ' €',
};
var counterback = new countUp.CountUp('back-goal-current', window.currentAmount, options);
var counterfront = new countUp.CountUp('front-goal-current', window.currentAmount, options);

function updateDonationBar() {
    const currentAmountUnit = window.currentAmount / 100;
    const percentage = Math.min(100, (currentAmountUnit / window.goalAmount) * 100);
    counterback.update(currentAmountUnit);
    counterfront.update(currentAmountUnit);
    document.querySelector('div.front').style["-webkit-clip-path"] = 'inset(0 ' + (100 - percentage) + '% 0 0 round 999px)';
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