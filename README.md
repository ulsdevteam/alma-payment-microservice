# Alma Payment Microservice

Provides an endpoint which compiles an Alma user's fees into an Authorize.net transaction and directs them to a hosted payment page

This microservice provides three endpoints: index.php, allowed_libraries.php, and receipt.php, as described here.

All endpoints expect a `jwt` url parameter for authentication, which should be a token granted by Alma/Primo.

## index.php

This endpoint implements the primary functionality of this service, which is to construct a transaction and return a token for use with Authorize.net's Accept Hosted solution. Which of the user's fees are included in the transaction depends on how this endpoint is called.

In the case of a GET request, the user's id is pulled from the JWT, and a transaction is constructed with the full amount for each of the user's outstanding fees that are owned by allowed libraries.

In the case of a POST request, the expected structure of the post data is that the key `userId` will have the Alma primary ID for the user, and the amount each of the fees to be paid will be under the key `fees[<feeId>]`, or in the case of paying against all fees, `fees[all]`.

As an example, if the user with the Alma ID `123` wanted to pay $5 towards the fee with ID `456` and $10 towards the fee with ID `789`, the post data would look like this:

```application/x-www-form-urlencoded
userId=123&fees[456]=5.00&fees[789]=10.00
```

Or, if using JSON:

```json
{
    "userId": "123",
    "fees": {
        "456": 5.00,
        "789": 10.00
    }
}
```

If they wanted their $15 to be distributed automatically among their eligible fees, then that would look like this:

```application/x-www-form-urlencoded
userId=123&fees[all]=15.00
```

or

```json
{
    "userId": "123",
    "fees": {
        "all": 15.00
    }
}
```

The data returned from this endpoint depends on the value of the request's `Accept` header. If it is `application/json`, it will return an object containing the `url` of the Authorize.net hosted payment page and the `token` to be posted to that page. Otherwise, it will return an html document that will automatically post the token to that url via Javascript when rendered.

## allowed_libraries.php

A GET request to this endpoint will return a JSON array of objects containing a `code` and `name` for each library that is allowed based on the allowlist or denylist in the configuration. The intended purpose of this is for filtering fees based on their owning library in the UI that uses this service.

## receipt.php

When a transaction is processed in Authorize.net, it is configured to POST a `net.authorize.payment.authcapture.created` event to this endpoint as a webhook. This endpoint gets the transaction data based on the ID contained in the event, then updates the Alma fees to be paid based on that transaction data.

## License

Copyright University of Pittsburgh.

Freely licensed for reuse under the MIT License.