
import { CountUp } from 'countup.js';
import updateDonationBar from './utilities.js';
let counterback;
let counterfront;
if (typeof CountUp !== 'undefined' && CountUp) {
    const options = {
        separator: ' ',
        separator: ' ',
        decimal: ',',
        suffix: ' €',
    };
    counterback = new CountUp('back-goal-current', window.currentAmount, options);
    counterfront = new CountUp('front-goal-current', window.currentAmount, options);
}
updateDonationBar(counterback, counterfront);
setInterval(fetch, 10000);


function fetch() {
    const request = new XMLHttpRequest()
    request.open("GET", '/widget-stream-donation/' + window.charityStreamId + '/fetch', true)
    request.onload = () => {
        if (request.status === 200) {
            const json = JSON.parse(request.response);
            window.currentAmount = json.amount;
            updateDonationBar(counterback, counterfront);
        }
        else {
            console.error('Erreur lors de la récupération des données de donation:', request.response);
        }
    }

    request.send()
}
export {
    fetch
}