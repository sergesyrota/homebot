var botBuilder = require('claudia-bot-builder');
var AWS = require('aws-sdk')
var sqs = new AWS.SQS();

module.exports = botBuilder(function (request) {
  console.log(request.text);
  var params = {
    MessageBody: JSON.stringify(request), /* required */
    QueueUrl: process.env.QUEUE_URL, /* required */
  };
  sqs.sendMessage(params, function(err, data) {
    if (err) console.log(err, err.stack); // an error occurred
    else     console.log(data);           // successful response
  });
  // This is required, otherwise sending message does not work =\
  return true;
});
