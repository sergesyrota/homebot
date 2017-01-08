This guide was used to get this deployed to lambda: https://aws.amazon.com/blogs/compute/create-and-deploy-a-chat-bot-to-aws-lambda-in-five-minutes/

Claudia needs credentials in ~/.aws/credentials, and specify --profile flag, pointing to proper section to be used
And bot builder functions need to return something valid. "true" is good enough to still work, without interfering with sqs.

aws-sdk package is not in the package.json, but it's essentially not needed. Looks like Amazon manages dependencies separately (or maybe it's just when I manually edit the function).
In any case, it's best to ommit it, as with this dependency, inline editing is not possible due to "package being too large" for that purpose.
