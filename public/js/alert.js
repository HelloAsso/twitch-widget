var alertQueue = [];
var isAlertActive = false;

function displayAlertBox(pseudo, message, amount) {
    message = message.substring(0, 255);
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
    container.innerHTML = '';

    var img = document.createElement('img');
    img.src = window.image;
    img.style.maxWidth = '100%';
    img.classList.add('fade');
    container.appendChild(img);

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

        container.innerHTML = '';
        processAlertQueue();
    }, window.alert_duration);
}

function fetch() {
    const request = new XMLHttpRequest()
    request.open("GET", '/widget-stream-alert/' + window.charityStreamId + '/fetch', true)
    request.onload = () => {
        if (request.status === 200) {
            const json = JSON.parse(request.response);
            json.donations.forEach(donation => {
                displayAlertBox(donation.pseudo, donation.message, donation.amount);
            });
        }
        else {
            console.error('Erreur lors de la récupération des données de donation:', request.response);
        }
    }

    request.send()
}