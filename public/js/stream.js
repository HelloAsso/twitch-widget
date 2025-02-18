if (typeof countUp !== 'undefined' && countUp) {
    const options = {
        separator: ' ',
        separator: ' ',
        decimal: ',',
        suffix: ' €',
    };
    var counterback = new countUp.CountUp('back-goal-current', window.currentAmount, options);
    var counterfront = new countUp.CountUp('front-goal-current', window.currentAmount, options);
}

function updateDonationBar() {
    const currentAmountUnit = window.currentAmount / 100;
    const percentage = Math.min(100, (currentAmountUnit / window.goalAmount) * 100);
    counterback.update(currentAmountUnit);
    counterfront.update(currentAmountUnit);
    document.querySelector('div.front').style["-webkit-clip-path"] = 'inset(0 ' + (100 - percentage) + '% 0 0 round 999px)';
}

function fetch() {
    const request = new XMLHttpRequest()
    request.open("GET", '/widget-stream-donation/' + window.charityStreamId + '/fetch', true)
    request.onload = () => {
        if (request.status === 200) {
            const json = JSON.parse(request.response);
            window.currentAmount = json.amount;
            updateDonationBar();
        }
        else {
            console.error('Erreur lors de la récupération des données de donation:', request.response);
        }
    }

    request.send()
}