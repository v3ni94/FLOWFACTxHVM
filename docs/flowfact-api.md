# FLOWFACT-API, bestätigte Erkenntnisse aus dem offiziellen SDK

Stand: 11.09.2026. Grundlage ist ausschließlich das npm-Paket `@flowfact/api-services` in Version 85.1.9. Alle Pfadangaben in den Quellenhinweisen sind relativ zur Paketwurzel (`package/`). Aussagen ohne Quellenhinweis gibt es in diesem Dokument nicht. Was das SDK nicht belegt, steht in Abschnitt 9.

---

## 1. Quelle und Verlässlichkeit

**Was untersucht wurde**

- Paket `@flowfact/api-services`, Version 85.1.9, Autor FLOWFACT GmbH, Selbstbeschreibung "middleware of FLOWFACT to interact between frontend and backend". Quelle: package.json:2-5
- Das Paket enthält kompiliertes JavaScript plus TypeScript-Deklarationen (`.d.ts`). Die `.js`-Dateien belegen Methode, Pfad, Query-Parameter, Header und Request-Body. Die `.d.ts`-Dateien belegen die Form der Antworten, soweit FLOWFACT sie typisiert hat. Quelle: package.json:20-21
- Upstream-Repository laut README: `https://github.com/FLOWFACT/ff-api-services.git`. Quelle: README.md:26
- Das README verweist auf eine Datei `Changelog.md`. Diese Datei ist im veröffentlichten Tarball nicht enthalten (Paketwurzel enthält nur `README.md`, `package.json`, `src/`). Aussagen zu Deprecations stammen daher ausschließlich aus `@deprecated`-Kommentaren im Code. Quelle: README.md:8 (Verweis) sowie Tarball-Inhalt (kein `package/Changelog.md`)
- Das Paket hat keine Tests. Quelle: README.md:49-51

**Verlässlichkeitsstufen in diesem Dokument**

| Stufe | Bedeutung |
|---|---|
| Bestätigt | Pfad, Methode, Parameter oder Typ stehen wörtlich im SDK. Quellenhinweis angegeben. |
| Abgeleitet | Aus mehreren SDK-Stellen logisch zusammengesetzt (z. B. der Ablauf einer Veröffentlichung). Als "rekonstruiert" gekennzeichnet. |
| Offen | Im SDK nicht belegt. Steht in Abschnitt 9 und ist am echten Konto zu prüfen. |

**Wichtige Einschränkungen**

- Das SDK ist ein Frontend-Client. Es zeigt, was der FLOWFACT-Browser-Client aufruft. Ob dieselben Endpunkte für externe API-Token-Nutzer freigeschaltet sind, belegt das SDK nicht.
- Antwortkörper sind nur dort bekannt, wo `.d.ts`-Typen existieren. Fehlerkörper sind mit einer Ausnahme (IS24, Abschnitt 5.8) nicht typisiert.
- Das SDK verwendet die Query-Serialisierung der Bibliothek `qs` mit der Option `addQueryPrefix: true`, sonst Standardoptionen. Parameter mit Wert `undefined` werden von `qs` ausgelassen. Quelle: src/http/APIClient.js:121

---

## 2. Basis-URLs und Stages

**Stages**

| Stage | Wert | Account | Quelle |
|---|---|---|---|
| Produktion | `production` | `flowfact-prod` | src/util/EnvironmentManagement.js:9, :36 |
| Staging | `staging` | `flowfact-prod` | src/util/EnvironmentManagement.js:10, :36 |
| Development | `development` | `flowfact-dev` | src/util/EnvironmentManagement.js:11, :36 |
| Local | `local` | `http://localhost:8080` | src/util/EnvironmentManagement.js:12, :37-39 |

Der Account ist nur für `development` gleich `flowfact-dev`, für alle anderen Stages `flowfact-prod`. Quelle: src/util/EnvironmentManagement.js:36

**Externe Basis-URL (für den PHP-Connector maßgeblich)**

```
https://api.<stage>.cloudios.<account>.cloud/<service-name>
```

Quelle: src/util/EnvironmentManagement.js:43 (Host) und src/http/APIClient.js:92 (Anhängen des Service-Namens)

Für Produktion ergibt sich daraus:

```
https://api.production.cloudios.flowfact-prod.cloud/<service-name>
```

**Interne Router-URL (nicht extern nutzbar)**

```
https://router-vs.<stage>.cloudios.internal.<account>.cloud
```

Quelle: src/util/EnvironmentManagement.js:41. Das SDK wählt diese URL automatisch, wenn es unter Node.js läuft (`internal = detect-node === true`). Quelle: src/http/APIClient.js:92. Der PHP-Connector muss deshalb ausdrücklich die externe `api.`-Variante verwenden.

**Service-Namen (Pfadsegment nach dem Host)**

| Service | Pfadsegment | Quelle |
|---|---|---|
| Entity-Service | `entity-service` | src/http/APIMapping.js:83 |
| Schema-Service | `schema-service` | src/http/APIMapping.js:134 |
| Multimedia-Service | `multimedia-service` | src/http/APIMapping.js:118 |
| Portal-Management-Service | `portal-management-service` | src/http/APIMapping.js:126 |
| IS24-Publish-Service | `is24-publish-service` | src/http/APIMapping.js:113 |
| Search-Service | `search-service` | src/http/APIMapping.js:135 |
| Property-Marketing-Phase-Service | `property-marketing-phase-service` | src/http/APIMapping.js:129 |
| Object-Phases-Lambda | `object-phases-lambda` | src/http/APIMapping.js:120 |
| Admin-Token-Service | `admin-token-service` | src/http/APIMapping.js:62 |
| User-Service | `user-service` | src/http/APIMapping.js:144 |
| Company-Service | `company-service` | src/http/APIMapping.js:69 |

Beispiel vollständige URL (Produktion, Entity-Service):

```
https://api.production.cloudios.flowfact-prod.cloud/entity-service/schemas/<schemaName>/entities/<entityId>
```

Quelle: Zusammensetzung aus src/util/EnvironmentManagement.js:43, src/http/APIMapping.js:83 und src/service/EntityService/EntityService.js:340

**Versionsmarkierung**

Es gibt zusätzlich ein `versionTag` (`latest` oder `stable`), das im SDK aber nicht in die URL einfließt. Quelle: src/util/EnvironmentManagement.js:14-20, :43

---

## 3. Authentifizierung und Standardheader

### 3.1 Drei Identifikationswege im SDK

Der `APIClient` bestimmt pro Request die Identifikation wie folgt. Quelle: src/http/APIClient.js:29-68

| Weg | Header | Bedingung im SDK | Quelle |
|---|---|---|---|
| API-Token | `x-ff-api-token: <token>` | Browser-`localStorage` enthält `flowfact.api.token` | src/http/APIClient.js:47, :56-58 |
| Support-Token | `x-ff-support-token: <token>` | `localStorage` enthält `flowfact.support.token` und kein API-Token | src/http/APIClient.js:46, :56-58 |
| Cognito | `cognitoToken: <idToken>` | weder API- noch Support-Token vorhanden | src/http/APIClient.js:51-55 |

**Kernaussage für den Connector:** Sobald ein API-Token vorliegt, sendet das SDK ausschließlich den Header `x-ff-api-token` und ruft Cognito nicht auf. Ein API-Token-Pfad ohne Cognito existiert damit clientseitig. Quelle: src/http/APIClient.js:51-58

Unter Node.js sendet das SDK keinen dieser Header, sondern höchstens eine `userId` (für das interne Netz gedacht). Quelle: src/http/APIClient.js:38-44

### 3.2 Standardheader je Request

| Header | Wert | Wann | Quelle |
|---|---|---|---|
| `Accept-Language` | Standard `de`, änderbar über `changeLanguages` | immer | src/http/APIClient.js:136, :177, :19-21 |
| `x-ff-version` | Zahl, z. B. `2` | nur wenn der Client oder die einzelne Methode eine Version setzt | src/http/APIClient.js:126; src/service/SchemaServiceV2/SchemaServiceV2.js:6; src/service/EntityService/EntityService.js:54-58 |
| `x-ff-company-id` | Company-UUID | nur wenn zuvor `setCompanyId` gesetzt wurde | src/http/APIClient.js:22-24, :137 |
| `Content-Type` | `application/json` oder `multipart/form-data` | wird pro Methode explizit gesetzt (siehe Abschnitt 5) | z. B. src/service/SearchService/SearchService.js:130-132; src/service/MultimediaService/ItemsController.js:49-51 |

Reihenfolge der Header-Zusammenführung: Identifikation, Sprache, Version, Company-ID, methodenspezifische Header (letztere überschreiben). Quelle: src/http/APIClient.js:138

### 3.3 Cognito-Parameter (nur falls der Cognito-Weg nötig wird)

| Parameter | Produktion | Quelle |
|---|---|---|
| Region | `eu-central-1` | src/authentication/Authentication.js:11 |
| User-Pool-ID | `eu-central-1_RdHzlSKS0` | src/authentication/Authentication.js:36 |
| Client-ID (FLOWFACT-Domain) | `4i5n6hiedala5o4r5ki2ainsb3` | src/authentication/Authentication.js:37 |
| Client-ID (Drittanbieter, "NPM") | `35r184son8kl2rfqmnri9ds6oa` | src/authentication/Authentication.js:39 |
| SSO-Client-ID | `55r8g2b7ij17759ug3ehbvqloe` | src/authentication/Authentication.js:38 |
| Identity-Pool-ID | `eu-central-1:2b79058f-3250-492a-a7a8-91bb06911ae9` | src/authentication/Authentication.js:35 |

Außerhalb einer FLOWFACT-Domain verwendet das SDK die Client-ID "NPM". Quelle: src/authentication/Authentication.js:46, :85-87. Login erfolgt mit Benutzername und Passwort über AWS Amplify `signIn`. Quelle: src/authentication/Authentication.js:146-158. Der `idToken` wird aus der aktuellen Session gelesen und als `cognitoToken`-Header gesendet. Quelle: src/authentication/Authentication.js:368-388; src/http/APIClient.js:53-55

### 3.4 API-Token anlegen (im SDK sichtbare Endpunkte)

Basis: `admin-token-service`. Alle Aufrufe setzen eine bereits authentifizierte Sitzung voraus (das SDK sendet die Identifikation aus 3.1 mit).

| Methode | Pfad | Zweck | Antwort | Quelle |
|---|---|---|---|---|
| POST | `/createOrReturnAdminToken` | Admin-Token erzeugen oder zurückgeben | untypisiert | src/service/AdminTokenService/AdminTokenController.js:17 |
| POST | `/admin-token?userType=API` | legt einen Benutzer vom Typ API an und speichert ein Token | `{ userId: string, token: string }` | src/service/AdminTokenService/AdminTokenController.js:29-33; src/service/AdminTokenService/AdminTokenController.d.ts:2-5 |
| GET | `/admin-token/{userId}` | Admin-Token eines Benutzers lesen | `string` | src/service/AdminTokenService/AdminTokenController.js:44; src/service/AdminTokenService/AdminTokenController.d.ts:22 |
| GET | `/public/adminUser/authenticate` mit Header `token: <platformToken>` | tauscht ein Plattform-Token gegen ein Cognito-Token | untypisiert | src/service/AdminTokenService/PublicAdminUserController.js:12-24 |
| GET | `/public/adminUser/authenticateAndReturnUsernameWithToken` mit Header `token: <platformToken>` | wie oben, liefert zusätzlich den Benutzernamen | untypisiert | src/service/AdminTokenService/PublicAdminUserController.js:27-42 |

Benutzertypen: `USER`, `EXPORTER`, `AUTOMATION`, `SYSTEM`, `API`, `UNKNOWN`. Quelle: src/service/UserService/UserService.Types.d.ts:15-22

Im `user-service` gibt es zusätzlich `POST /internal/token/user/{userId}/api-token?name=<tokenName>`. FLOWFACT markiert diesen Endpunkt ausdrücklich als nur aus dem internen Netz erreichbar. Quelle: src/service/UserService/InternalController.js:98-101, :109-113. Die Token-Entität hat die Form `{ active, created, id, lastLogin, name, userId }`. Quelle: src/service/UserService/UserService.Types.d.ts:78-85

Wie ein API-Token in der FLOWFACT-Oberfläche erzeugt wird, zeigt das SDK nicht (Abschnitt 9).

### 3.5 Fehlerbehandlung

- Erfolgreich sind Statuscodes 200 bis 299. Bei 204 oder leerem Body liefert das SDK `data = undefined`. Quelle: src/http/APIClient.js:161-164
- Das SDK übersetzt nur den Statuscode in einen Text, nicht den Fehlerkörper: 204, 400, 401, 403, 404, 408, 410, 429, 500. Quelle: src/http/statusCodes.js:4-28
- Der Fehlerkörper ist nur für den IS24-Publish-Service typisiert (Abschnitt 5.8). Sonst offen (Abschnitt 9).

---

## 4. Entitätsmodell

### 4.1 Werteform

Jede Entität besteht aus `id`, Systemfeldern mit Unterstrich-Präfix und Fachfeldern. Jedes Fachfeld ist ein Objekt mit einem Array `values`. Quelle: src/types/entities/EntityValuesField.d.ts:1-3; src/service/EntityService/EntityService.Types.d.ts:57-81

```
Entity = {
  id: string,
  _metadata: EntityMetadata,
  _acls: EntityACL[],
  _refs: EntityRef[],
  _acps: ACP[],
  _optionalConditions?: ...,
  _lock?: ...,
  <feldname>: { values: [ ... ] },
  ...
}
```

Quelle: src/types/entities/EntityBase.d.ts:3-11; src/service/EntityService/EntityService.Types.d.ts:73-81

`EntityMetadata`: Quelle: src/service/EntityService/EntityService.Types.d.ts:35-46

```
_metadata = {
  id, timestamp, creator, createdTimestamp, documentType,
  schema,                 // Schemaname der Entität
  integration?, currentAccessLevel, lastModifier?, lastModifiedTimestamp?
}
```

`EntityFieldValues` kann neben `values` noch `lastCalculated` und `optionalConditions` tragen. Quelle: src/service/EntityService/EntityService.Types.d.ts:57-64

Komplexe Feldtypen (Adresse, Bank, Telefon): Quelle: src/types/entities/ComplexFieldTypes.d.ts:1-24

```
AddressEntityField = { city, country, street, type, zipcode, geolocation?: { latitude, longitude }, internationalregion? }
```

Eine zweite, ausführlichere Adressdefinition mit `state` und `district` sowie `type` in `'private' | 'office' | 'other' | ''` existiert unter src/types/common/EntityFieldTypes.d.ts:1-15.

### 4.2 Estate-Felder laut SDK-Typ

Quelle: src/types/entities/estates/Estate.d.ts:195-234

| Feld | Typ der Werte | Feld | Typ der Werte |
|---|---|---|---|
| `headline` | string | `identifier` | string |
| `addresses` | AddressEntityField | `estatetype` | Code (Enum) |
| `purchaseprice` | number | `rent` | number |
| `livingarea` | number | `plotarea` | number |
| `totalarea` | number | `commercialarea` | number |
| `gardenarea` | number | `rooms` | number |
| `numberbedrooms` | number | `numberbathrooms` | number |
| `floor` | number | `no_of_floors` | number |
| `yearofconstruction` | number | `condition` | Code (Enum) |
| `energyefficienceclass` | Code (Enum) | `parking` | Code (Enum) |
| `elevator` | boolean | `elevator_general` | Code (Enum) |
| `balconyavailable` | boolean | `balconies` | number |
| `cellar` | boolean | `guesttoilet` | boolean |
| `barrierfree` | boolean | `monument` | boolean |
| `let` | boolean | `assisted_living` | boolean |
| `status` | `active`, `inactive`, `archived` | `objectPhase` | string |
| `tags` | Enum | `contact` | string |
| `note` | string | `internaldescription` | string |
| `access` | number | `currentFlowStep` | leerer Enum |

Enum-Werte (Auszug):

- `status`: `active`, `inactive`, `archived`. Quelle: src/types/entities/estates/Estate.d.ts:4-8
- `condition`: Codes `01` bis `11`, z. B. `01` Erstbezug, `03` neuwertig, `05` modernisiert, `06` renoviert, `07` gepflegt, `08` renovierungsbedürftig, `10` projektiert, `11` unsaniert. Quelle: src/types/entities/estates/Estate.d.ts:9-21
- `energyefficienceclass`: `01` A+, `02` A, `03` B, `04` C, `05` D, `06` E, `07` F, `08` G, `09` H. Quelle: src/types/entities/estates/Estate.d.ts:27-37
- `estatetype`: FLOWFACT-Codes, z. B. `01ETAG` Etagenwohnung, `01ZAPART` Apartment, `01DACH` Dachgeschoss, `01MAIS` Maisonette, `01PENT` Penthouse, `02EFH` Einfamilienhaus, `02DHH` Doppelhaushälfte, `02REH` Reihenhaus, `02MFH` Mehrfamilienhaus, `03BE` Baugrund Einfamilienhaus, `04W03` Anlage Mehrfamilienhaus, `06B` Bürofläche, `05L` Ladenfläche. Vollständige Liste: Quelle: src/types/entities/estates/Estate.d.ts:40-175
- `parking`: `1` keine Angabe, `2` Garage, `3` Außenstellplatz, `4` Carport, `5` Duplex, `6` Parkdeck, `7` Tiefgarage. Quelle: src/types/entities/estates/Estate.d.ts:176-184
- `tags`: `important`, `internal`, `houseOnly`, `teaser`, `renovation`, `ownUse`, `cooperative`, `propertyManagement`. Quelle: src/types/entities/estates/Estate.d.ts:185-194

Alle Felder sind im Typ optional. Welche Felder das konkrete Estate-Schema des Kontos tatsächlich enthält und welche pflichtig sind, liefert nur der Schema-Service (Abschnitt 5.2).

### 4.3 Beispiel eines Estate-Payloads (Anlage)

Zusammengesetzt aus den bestätigten Feldnamen und der Werteform. Feldauswahl ist ein Beispiel, keine Pflichtliste.

```json
{
  "headline":            { "values": ["Helle 3-Zimmer-Wohnung mit Balkon"] },
  "identifier":          { "values": ["HVM-2026-000123"] },
  "estatetype":          { "values": ["01ETAG"] },
  "status":              { "values": ["active"] },
  "addresses":           { "values": [{
                             "type": "private",
                             "street": "Musterstraße 12",
                             "zipcode": "40721",
                             "city": "Hilden",
                             "country": "Deutschland"
                          }] },
  "purchaseprice":       { "values": [349000] },
  "livingarea":          { "values": [78.5] },
  "rooms":               { "values": [3] },
  "floor":               { "values": [2] },
  "yearofconstruction":  { "values": [1998] },
  "condition":           { "values": ["07"] },
  "energyefficienceclass": { "values": ["04"] },
  "balconyavailable":    { "values": [true] },
  "elevator":            { "values": [true] },
  "cellar":              { "values": [true] }
}
```

Quellen: Werteform src/types/entities/EntityValuesField.d.ts:1-3; Feldnamen src/types/entities/estates/Estate.d.ts:195-234; Adressstruktur src/types/entities/ComplexFieldTypes.d.ts:1-12; Enum-Codes wie in 4.2.

### 4.4 Änderungsoperationen

Es gibt drei unterschiedliche Update-Formen im Entity-Service.

**a) Standard-PATCH (Feld-Objekt, kein JSON-Patch)**

`PATCH /schemas/{schemaId}/entities/{entityId}` mit Body vom Typ `EntityFields`, also dieselbe Form wie bei der Anlage (`{ feld: { values: [...] } }`). Quelle: src/service/EntityService/EntityService.js:264-270; src/service/EntityService/EntityService.d.ts:106; src/service/EntityService/EntityService.Types.d.ts:67-69

**b) Deep-PATCH mit Operation**

`PATCH /schemas/{schemaId}/entities/{entityId}/deep` mit Body `{ "op": "<add|remove|replace>", "value": { feld: { values: [...] } } }`. Der Service berücksichtigt dabei die Feldkonfiguration (`hasMultipleValues`, `maxItems`): Einzelwertfelder werden überschrieben, Mehrwertfelder ergänzt. Quelle: src/service/EntityService/EntityService.js:295-312; src/types/common/PatchOperation.d.ts:1-5

**c) JSON-Patch-Operationen**

Typ `PatchOperation = { op: 'add' | 'remove' | 'replace', path: string, value: any }`. Quelle: src/types/common/PatchOperation.d.ts:6-10. Im untersuchten Umfang wird diese Form nur für Multimedia-Items verwendet (`PATCH /items/{mediaItemId}` mit `jsonPatch: object[]`). Quelle: src/service/MultimediaService/ItemsController.js:235-239; src/service/MultimediaService/ItemsController.d.ts:89

### 4.5 Schemanamen und Gruppen

Bekannte Schemanamen und Gruppen: Quelle: src/types/schemas/SchemaNames.d.ts:1-35

| Konstante | Name | Bemerkung |
|---|---|---|
| ESTATES | `estates` | Gruppe der Immobilienschemata |
| CONTACTS | `contacts` | |
| DOCUMENTS | `documents` | |
| INQUIRIES | `inquiries` | |
| APPOINTMENTS | `appointments` | |
| DEVELOPER_PROJECTS | `developer_projects` | |
| PROJECTS | `flowfact_projects` | |
| NOTES | `notes` | |
| TASKS | `tasks` | |

`estates` ist eine Gruppe, die konkrete Schemata enthält. Der Schema-Service bietet dafür `resolveGroup` ("resolves groups, like estates, to hist children"). Quelle: src/service/SchemaServiceV2/SchemaServiceV2.js:95-100. Ein konkreter Estate-Schemaname, den das SDK selbst hart verwendet, ist `house_purchase`. Quelle: src/service/MultimediaService/ItemsController.js:132. Welche konkreten Estate-Schemata das Konto hat, ist per `GET /v2/schemas?group=estates` zu ermitteln (Abschnitt 5.2).

**Schema-Definition (V2)**: Quelle: src/types/schemas/SchemaV2.d.ts:67-81

```
SchemaV2 = { id?, name, captions, global, properties: { <feld>: SchemaV2Property }, groups: string[], visualization }
SchemaV2Property = { type, subtype?, unit?, hasMultipleValues?, readOnly?, maxItems?, captions, fields?, lnk_schema?, initialValues? }
```

Feldtypen: `TEXT`, `TEXTAREA`, `URL`, `PASSWORD`, `EMAIL`, `CHECKBOX`, `LIST`, `NUMBER`, `DATE`, `SCHEMA`, `PHONE`, `ADDRESS`, `MEDIA`, `RANGE`, `BANK`, `USER`, `SCHEMA_NAME`, `EMAIL_ACCOUNT`, `JSON`, `GEO_POINT`, `COLOR`, `METADATA_DATE`. Quelle: src/types/schemas/SchemaV2.d.ts:7-30. Subtypen für MEDIA u. a.: `IMAGE`, `VIDEO`, `DOCUMENT`, `LINK`. Quelle: src/types/schemas/SchemaV2.d.ts:31-39

---

## 5. Endpunkte je Service

Alle Pfade sind relativ zur Service-Basis aus Abschnitt 2. "v2" in der Spalte Request bedeutet: das SDK sendet `x-ff-version: 2`.

### 5.1 Entity-Service (`entity-service`)

| Methode | Pfad | Zweck | Request | Response | Quelle |
|---|---|---|---|---|---|
| POST | `/schemas/{schemaId}` | Entität anlegen | Body: Entität in Werteform (Abschnitt 4.3), v2 | laut Typ `Entity`; Kommentar nennt "the created entity id" (zu prüfen, Abschnitt 9) | src/service/EntityService/EntityService.js:65-76; src/service/EntityService/EntityService.d.ts:25 |
| GET | `/schemas/{schemaId}/entities/{entityId}` | Entität lesen | keine Parameter | `Entity` | src/service/EntityService/EntityService.js:337-342; src/service/EntityService/EntityService.d.ts:141 |
| PATCH | `/schemas/{schemaId}/entities/{entityId}` | Felder ändern | Body `EntityFields` (Werteform) | `Entity` | src/service/EntityService/EntityService.js:264-270; src/service/EntityService/EntityService.d.ts:106 |
| PATCH | `/schemas/{schemaId}/entities/{entityId}/deep` | Ändern mit Operation | Body `{ op, value: EntityFields }` | `Entity` | src/service/EntityService/EntityService.js:305-312; src/service/EntityService/EntityService.d.ts:128 |
| PATCH | `/schemas/{schemaId}/entities/{entityId}?sendWithNotification=true` | Ändern und Benachrichtigung auslösen | Body `{ modifiedFields, notification }`, v2 | `{ patchedEntity, notificationResponse }` | src/service/EntityService/EntityService.js:282-292; src/service/EntityService/EntityService.Types.d.ts:82-85 |
| DELETE | `/schemas/{schemaId}/entities/{entityId}` | Entität löschen | keine | untypisiert | src/service/EntityService/EntityService.js:224-230 |
| DELETE | `/schemas/{schemaName}/entities` | mehrere löschen (Schema oder Gruppe) | Body `{ entityIds: string[] }` | `{ responses: { [entityId]: { response, statusCode } } }` | src/service/EntityService/EntityService.js:249-255; src/service/EntityService/EntityService.Types.d.ts:86-93 |
| DELETE | `/entities` | mehrere löschen über Schemata hinweg | Body `{ entities: [{ entityId, schema }] }`, v2 | wie oben | src/service/EntityService/EntityService.js:237-243; src/service/EntityService/EntityService.d.ts:93-95 |
| POST | `/search/schemas/{index}?page=&size=&viewName=&withCount=` | Suche mit Flowdsl, Ergebnis als View | Body Flowdsl (JSON), v2, `Content-Type: application/json`; Standard `page=1`, `size=20` | `PagedResponse<...>` | src/service/EntityService/EntityService.js:144-160; src/service/EntityService/EntityService.d.ts:62 |
| POST | `/search/schemas/{index}?offset=&size=&viewName=&withCount=` | Suche, virtualisiert (v1 und v2 Variante) | Body Flowdsl | `PagedResponse<...>` | src/service/EntityService/EntityService.js:172-216 |
| POST | `/schemas/{schemaId}/entities/{entityId}/duplicate?targetSchema=` | Entität samt Medien duplizieren | Query `targetSchema` optional | `string` (neue UUID) | src/service/EntityService/EntityService.js:420-438; src/service/EntityService/EntityService.d.ts:183 |
| POST | `/schemas/{schemaName}/previous?previousSchemaName=&previousEntityId=` | neue Entität mit Werten einer vorhandenen anlegen | Query-Parameter | `Entity` | src/service/EntityService/EntityService.js:90-100 |
| GET | `/entities/{entityId}` | Entität ohne Schema lesen (deprecated) | keine | `Entity` | src/service/EntityService/EntityService.js:440-450 |
| GET | `/entities/{entityId}` mit `Accept: application/json+descriptor` | Deskriptor lesen | Header | untypisiert (deprecated Typ) | src/service/EntityService/EntityService.js:350-356 |
| GET | `/schemas/{schemaId}/entities/{entityId}/history?page=&size=15&order=DESC` | Historie (deprecated, history-service nutzen) | Query | untypisiert | src/service/EntityService/EntityService.js:364-372 |
| GET | `/schemas/{schemaId}/entities/{entityId}/users/{userId}/hasaccess/{accessType}` | Zugriff prüfen | `accessType` in `READ`, `UPDATE`, `SEARCH`, `DELETE` | `{ schemaId, entityId, hasAccess }` | src/service/EntityService/EntityService.js:382-386; src/service/EntityService/EntityService.Types.d.ts:5, :30-34 |
| GET | `/prefixes` | Nummernkreis-Präfixe je Schema | keine | `{ prefixes: [{ prefix, schema, defaultForSchemas? }] }` | src/service/EntityService/EntityService.js:456-462; src/service/EntityService/EntityService.Types.d.ts:94-101 |
| POST | `/prefixes` | Präfix setzen | Body mit `schema`, `prefix` | void | src/service/EntityService/EntityService.js:470-482 |
| GET | `/recovery/entities?page=&size=50&schema=&returnIds=` | Papierkorb lesen | Query | `{ entries: [{ content, schemaName, entityId, deletedAt, deletedBy }], totalCount, page, pageSize, offset, size }` | src/service/EntityService/EntityService.js:492-506; src/service/EntityService/EntityService.Types.d.ts:102-116 |
| POST | `/recovery/entities` | wiederherstellen | Body `{ entityIds }` | untypisiert | src/service/EntityService/EntityService.js:529-535 |
| DELETE | `/recovery/entities` | endgültig löschen | Body `{ entityIds }` | untypisiert | src/service/EntityService/EntityService.js:514-520 |
| GET | `/recovery/schemas` | Schemata mit Papierkorb-Einträgen | keine | `{ schemas: [{ schema }] }` | src/service/EntityService/EntityService.js:543-549; src/service/EntityService/EntityService.Types.d.ts:117-122 |
| POST | `/users/{userId}/hasaccess/{accessType}` | Zugriff für mehrere Entitäten prüfen | Body `[{ entityId, schemaId }]` | `EntityAccess[]` | src/service/EntityService/EntityService.js:395-403; src/service/EntityService/EntityService.d.ts:169 |
| GET | `/views/{viewId}/schemas/{schemaId}/entities/{entityId}` | Entität mit View-Definition lesen | keine (Achtung: das SDK übergibt die Header hier fälschlich als Body-Argument) | `DeprecatedEntityView` | src/service/EntityService/EntityService.js:323-331; src/service/EntityService/EntityService.d.ts:135 |
| POST | `/views/{viewName}/entities` | mehrere Entitäten als View aufbereiten | Body `[{ entityId, schemaId }]` | `DeprecatedEntityView[]` | src/service/EntityService/EntityService.js:410-418; src/service/EntityService/EntityService.d.ts:176 |
| GET | `/views/{viewId}/schemas/{schemaId}/entities/{entityId}/stringify` | Entität als Text (deprecated), Standard `viewId=EntityRelationView` | keine | `string` | src/service/EntityService/EntityService.js:110-119; src/service/EntityService/EntityService.d.ts:45 |
| GET | `/views/schemas/{schemaName}/entities/{entityId}/stringify` | Entität als Text nach Typ (deprecated) | keine | `string` | src/service/EntityService/EntityService.js:126-134; src/service/EntityService/EntityService.d.ts:52 |

Hinweise:

- Eine reine GET-Liste aller Entitäten eines Schemas gibt es im Entity-Service nicht. Auflistung läuft über `POST /search/schemas/{index}` oder über den Search-Service (5.6). Quelle: keine GET-Listenmethode in src/service/EntityService/EntityService.js:65-554
- Bei aktivem Feature-Flag `entity-service.client.headers.current-path.enabled` sendet der Browser-Client zusätzlich `x-ff-request-path`. Für den Connector irrelevant. Quelle: src/service/EntityService/EntityService.js:7, :30-46
- Deprecated laut Code: `stringifyEntity`, `stringifyEntityByType`, `fetchHistory`, `fetchEntityWithoutSchemaId`. Quelle: src/service/EntityService/EntityService.js:108, :124, :365, :443

### 5.2 Schema-Service (`schema-service`)

**V2 (empfohlen; die v1-Schemamethoden `fetchSchema`, `createSchema`, `updateSchema`, `deleteSchema`, `deleteAllSchema` und `fetchAllMembersOfGroup` sind laut Code deprecated)**

| Methode | Pfad | Zweck | Request | Response | Quelle |
|---|---|---|---|---|---|
| GET | `/v2/schemas?group=&size=&page=&extensions=` | alle Schemata, optional nach Gruppe (z. B. `estates`) | Query, alle optional | `{ entries: SchemaV2[], totalCount, page, pageSize }` | src/service/SchemaServiceV2/SchemaServiceV2.js:20-45; src/types/schemas/SchemaV2.d.ts:96-101 |
| GET | `/v2/schemas/{schemaIdOrName}?extensions=all&resolveGroup=` | ein Schema (ID oder Name) | Standard `extensions=all` | `SchemaV2` | src/service/SchemaServiceV2/SchemaServiceV2.js:95-114; src/service/SchemaServiceV2/SchemaServiceV2.d.ts:52-55 |
| GET | `/v2/schemas/{schemaName}/exists` | Existenz prüfen, liefert ID | keine | `string` | src/service/SchemaServiceV2/SchemaServiceV2.js:116-123 |
| GET | `/v2/schemas/resolveIndices?identifier=` | Indizes zu Gruppe oder Schema | Query | `string[]` | src/service/SchemaServiceV2/SchemaServiceV2.js:149-156 |
| GET | `/v2/schemas/resolveName?name=` | Namen auflösen | Query | `string` | src/service/SchemaServiceV2/SchemaServiceV2.js:158-165 |
| GET | `/groups` | alle Gruppen | v2 | `{ items: SchemaGroupV2[] }` | src/service/SchemaServiceV2/SchemaServiceV2.js:168-174; src/types/schemas/SchemaV2.d.ts:102-104 |
| GET | `/groups/{identifier}` | Gruppe lesen | v2 | `SchemaGroupV2` | src/service/SchemaServiceV2/SchemaServiceV2.js:194-201 |
| GET | `/groups/{identifier}/is-group` | ist Identifier eine Gruppe | v2 | untypisiert | src/service/SchemaServiceV2/SchemaServiceV2.js:212-219 |
| GET | `/extensions/schemas/{schemaName}` | Erweiterungen eines Schemas | v2 | untypisiert | src/service/SchemaServiceV2/SchemaServiceV2.js:268-275 |
| GET | `/datatypes/alltypes` | komplexe Datentypen | v2 | `ComplexDataTypes` | src/service/SchemaServiceV2/SchemaServiceV2.js:307-316; src/types/schemas/SchemaV2.d.ts:105-107 |
| POST, PUT, DELETE | `/v2/schemas`, `/v2/schemas/{id}` | Schema anlegen, ändern, löschen | Body `SchemaV2` | `SchemaV2` | src/service/SchemaServiceV2/SchemaServiceV2.js:15-18, :47-63 |
| POST | `/v2/schemas?sourceSchemaName=&targetSchemaName=` | Schema duplizieren | Query, kein Body | `SchemaV2` | src/service/SchemaServiceV2/SchemaServiceV2.js:85-93; src/service/SchemaServiceV2/SchemaServiceV2.d.ts:42 |
| POST | `/schemas/{targetSchemaName}/field-import` | Felder aus anderem Schema übernehmen | Body `{ sourceSchema, fields: string[] }` | `SchemaV2` | src/service/SchemaServiceV2/SchemaServiceV2.js:72-79; src/service/SchemaServiceV2/SchemaServiceV2.d.ts:36 |
| DELETE | `/v2/schemas/deleteAll?key=DELETE` | alle Schemata der Company löschen (nie im Connector verwenden) | Query `key=DELETE` zwingend | untypisiert | src/service/SchemaServiceV2/SchemaServiceV2.js:140-147 |
| POST | `/v2/schemas/{schemaName}/fields/{fieldName}/possiblevalues` | Auswahlwert ergänzen (deprecated) | Body Wert | untypisiert | src/service/SchemaServiceV2/SchemaServiceV2.js:131-135 |
| POST, PUT, DELETE | `/groups`, `/groups/{id}`, `/groups/{identifier}` | Gruppe anlegen, ändern, löschen | Body `SchemaGroupV2`, v2 | `string` (POST), `SchemaGroupV2` (PUT) | src/service/SchemaServiceV2/SchemaServiceV2.js:179-210; src/service/SchemaServiceV2/SchemaServiceV2.d.ts:92-107 |
| GET, POST | `/extensions` | alle Erweiterungen lesen, Erweiterung anlegen (409 bei Namenskonflikt) | Body `Extension`, v2 | untypisiert bzw. `Extension` | src/service/SchemaServiceV2/SchemaServiceV2.js:224-228, :253-257 |
| GET, PUT, DELETE | `/extensions/{name}` | Erweiterung lesen, anlegen oder ändern, löschen | Body `Extension`, v2 | untypisiert | src/service/SchemaServiceV2/SchemaServiceV2.js:233-247, :262-266 |
| GET | `/extensions/assignments` | Statistik zugewiesener Erweiterungen | v2 | untypisiert | src/service/SchemaServiceV2/SchemaServiceV2.js:280-284 |
| POST, DELETE | `/extensions/assignments/{name}` | Erweiterung zuweisen bzw. Zuweisung entziehen | v2 | untypisiert | src/service/SchemaServiceV2/SchemaServiceV2.js:290-303 |

`Extension = { name, schemas: string[], captions, descriptions?, metadata?, properties? }`. Quelle: src/types/schemas/SchemaV2.d.ts:82-95

**V1 (nur zur Kenntnis; deprecated sind nur die gekennzeichneten Zeilen, die übrigen v1-Methoden tragen keinen Vermerk, nutzen aber den deprecated `invokeApi`-Aufruf)**

| Methode | Pfad | Zweck | Quelle |
|---|---|---|---|
| GET | `/schemas?transform=true&groups=true&short=true` | alle Schemata (v1), nicht als deprecated markiert | src/service/SchemaService/SchemaService.js:34-52 |
| GET | `/schemas/{schemaId}?transform=true` | ein Schema (v1), deprecated zugunsten `fetchSchemaByIdOrName` | src/service/SchemaService/SchemaService.js:75-88 |
| POST, PUT, DELETE | `/schemas?transform=true`, `/schemas/{id}?transform=true`, `/schemas/{id}`, `/schemas/deleteAll?key=DELETE` | Schema anlegen, ändern, löschen (v1), alle deprecated | src/service/SchemaService/SchemaService.js:90-140 |
| GET | `/groups/{groupId}/members` | Mitglieder einer Gruppe (v1), deprecated zugunsten `GET /v2/schemas?group=` | src/service/SchemaService/SchemaService.js:242-252 |
| GET | `/stats?groups=true` | Statistik, nicht als deprecated markiert | src/service/SchemaService/SchemaService.js:15-28 |
| GET | `/data/{schemaId}?page=&size=` | Datentypen zu Schema, nicht als deprecated markiert | src/service/SchemaService/SchemaService.js:59-73 |
| GET | `/datatypes/alltypes?transform=true` | komplexe Datentypen (v1-Variante) | src/service/SchemaService/SchemaService.js:256-266 |
| GET, POST, PUT, DELETE | `/integrations?schemaId=&transform=true`, `/integrations/{id}`, `/integrations/{id}/formdata`, `/integrations/{id}/data` | Integrationen eines Schemas (für den Connector ohne Bedeutung) | src/service/SchemaService/SchemaService.js:145-239 |

### 5.3 Multimedia-Service (`multimedia-service`)

**Items (ItemsController)**

| Methode | Pfad | Zweck | Request | Response | Quelle |
|---|---|---|---|---|---|
| GET | `/items/schemas/house_purchase/entities/{entityId}/presigned-url?contentType=&fileName=&fileSize=` | vorsignierte Upload-URL holen | Query: MIME-Typ, Dateiname, Größe in Byte. Schemaname im SDK hart `house_purchase` | `{ presignedUrl, itemLink }` | src/service/MultimediaService/ItemsController.js:119-142; src/service/MultimediaService/MultimediaService.Types.d.ts:52-55 |
| POST | `/items/schemas/{schemaName}/entities/{entityId}` | Multimedia-Item anlegen (nach Upload) | Body `{ contentType, fileName, fileSize, itemLink, title?, albumAssignments: [{ albumName, categories? }] }` | `{ multimediaItem: MultimediaItem }` | src/service/MultimediaService/ItemsController.js:149-167; src/service/MultimediaService/MultimediaService.Types.d.ts:56-65, :49-51 |
| POST | `/upload/{entityId}` | Direkt-Upload (deprecated) | `multipart/form-data`, Feld `file` | untypisiert | src/service/MultimediaService/ItemsController.js:31-56 |
| POST | `/items/{itemId}/file` | Binärdatei eines Items ersetzen | `multipart/form-data` | `{ url, etag }` | src/service/MultimediaService/ItemsController.js:11-30; src/service/MultimediaService/ItemsController.d.ts:11-14 |
| GET | `/items/entities/{entityId}?contentCategory=` | alle Items einer Entität | `contentCategory` optional: `IMAGE`, `DOCUMENT`, `LINK`, `VIDEO` | `MultimediaItem[]` | src/service/MultimediaService/ItemsController.js:85-103; src/service/MultimediaService/MultimediaService.Types.d.ts:2 |
| GET | `/items/{mediaItemId}` | ein Item | keine | `MultimediaItem` | src/service/MultimediaService/ItemsController.js:108-117 |
| PATCH | `/items/{mediaItemId}` | Item-Eigenschaft ändern (z. B. Titel) | Body JSON-Patch `object[]` | `MultimediaItem` | src/service/MultimediaService/ItemsController.js:235-244; src/service/MultimediaService/ItemsController.d.ts:89 |
| DELETE | `/items/{mediaItemId}` | Item löschen inkl. Zuordnungen | keine | untypisiert | src/service/MultimediaService/ItemsController.js:170-182 |
| POST | `/items/schemas/{schemaName}/entities/{entityId}/link` | Link (z. B. Video-URL) anhängen | Body `{ link, title?, albumAssignments }` | `{ multimediaItem }` | src/service/MultimediaService/ItemsController.js:212-229 |
| POST | `/items/schemas/{schemaName}/entities/{entityId}/transfers` | Items von anderer Entität kopieren | Body `{ sourceSchemaName, sourceEntityId, selectedMultimediaItems: number[], selectedAlbums: string[] }` | `{ entries: MultimediaItem[], size }` | src/service/MultimediaService/ItemsController.js:260-274; src/service/MultimediaService/ItemsController.d.ts:105-108 |
| POST | `/items/schemas/{schemaName}/entities/{entityId}/copy` | Items zwischen Alben kopieren | Body `{ copyItems: [{ selectedMultimediaItem, sorting, targetAlbum, targetCategory }] }` | `{ entries, size }` | src/service/MultimediaService/ItemsController.js:290-300; src/service/MultimediaService/MultimediaService.Types.d.ts:66-74 |
| GET | `/download?uri=` | Datei laden (deprecated) | `Accept: application/octet-stream` | Binärdaten | src/service/MultimediaService/ItemsController.js:59-83 |
| DELETE | `/deleteFile` | S3-Datei löschen | Body `{ bucketType: 'Image' oder 'Document', entityId, filename }` | untypisiert | src/service/MultimediaService/ItemsController.js:188-200 |

`MultimediaItem = { id: number, createdAt, entityId, schemaName, description?, title?, fileName?, contentType?, contentCategory, fileReference, fileSize? }`. Quelle: src/service/MultimediaService/MultimediaService.Types.d.ts:3-15. Die Item-ID ist numerisch.

**Alben (AlbumsController)**

| Methode | Pfad | Zweck | Request | Response | Quelle |
|---|---|---|---|---|---|
| GET | `/albums/schemas/{schemaName}` | Albumdefinitionen eines Schemas | keine | `Album[]` | src/service/MultimediaService/AlbumsController.js:15-24 |
| GET | `/albums/{albumName}/schemas/{schemaName}` | ein Album | keine | `Album` | src/service/MultimediaService/AlbumsController.js:30-39 |
| POST | `/albums` | eigenes Album anlegen | Body `Album` | `Album` | src/service/MultimediaService/AlbumsController.js:44-53 |
| PUT | `/albums/{albumId}` | Album ändern | Body `Album` | `Album` | src/service/MultimediaService/AlbumsController.js:59-68 |
| DELETE | `/albums/{albumId}` | Album löschen | keine | `Album` | src/service/MultimediaService/AlbumsController.js:73-82 |

`Album = { id, schema, name, captions, descriptions?, categories: Category[], hidden, companyId? }`, `Category = { id, name, captions, maxItems?, sorting, allowedContentCategories, companyId? }`. Quelle: src/service/MultimediaService/MultimediaService.Types.d.ts:30-48

**Zuordnungen und Reihenfolge (AlbumAssignmentController)**

| Methode | Pfad | Zweck | Request | Response | Quelle |
|---|---|---|---|---|---|
| GET | `/assigned/schemas/{schemaName}/entities/{entityId}?albumName=&short=false` | Zuordnungen eines Albums | Query | `{ assignments: { [kategorie]: [{ multimedia, sorting }] } }` | src/service/MultimediaService/AlbumAssignmentController.js:11-24; src/service/MultimediaService/MultimediaService.Types.d.ts:20-29 |
| GET | dito mit `short=true` | nur IDs | Query | wie oben, verkürzt | src/service/MultimediaService/AlbumAssignmentController.js:26-39 |
| GET | `/unassigned/entities/{entityId}?albumName=&short=false` | nicht zugeordnete Items | Query | `{ unassignedIds: MultimediaItem[] }` | src/service/MultimediaService/AlbumAssignmentController.js:41-56 |
| PUT | `/assigned/schemas/{schemaName}/entities/{entityId}/items` | Items einem Album zuordnen, Kategorie automatisch wenn leer | Body `{ albumName, categories: string[], multimediaItemIds: number[] }` | untypisiert | src/service/MultimediaService/AlbumAssignmentController.js:98-118 |
| DELETE | `/assigned/schemas/{schemaName}/entities/{entityId}/items?albumName=` | Zuordnung entfernen | Body `{ multimediaItemIds }` | untypisiert | src/service/MultimediaService/AlbumAssignmentController.js:120-133 |
| PUT | `/assigned/schemas/{schemaName}/entities/{entityId}?albumName=&short=false` | komplette Zuordnung inkl. Sortierung setzen | Body `{ assignments: { [kategorie]: [{ multimedia: MultimediaItem, sorting: number }] } }` | `MultimediaAssignments` | src/service/MultimediaService/AlbumAssignmentController.js:58-80 |
| POST | `/assigned/schemas/{schemaName}/entities/{entityId}?albumName=` | Zuordnungen übernehmen (Kategorien wenn möglich) | Body `{ assignments }` | untypisiert | src/service/MultimediaService/AlbumAssignmentController.js:135-152 |
| GET | `/assigned/schemas/{schemaName}/entities/{entityId}/items/{mediaItemId}` | Alben eines Items | keine | `{ albums: Album[] }` | src/service/MultimediaService/AlbumAssignmentController.js:82-96 |

### 5.4 Portal-Management-Service (`portal-management-service`)

**Portale (PortalController)**

| Methode | Pfad | Zweck | Request | Response | Quelle |
|---|---|---|---|---|---|
| GET | `/portals?ignoreInactivePortals=false&type=` | alle Portale der Company | `type` optional: `IS24`, `OPENIMMO`, `WORDPRESS` | `Portal[]` | src/service/PortalManagementService/PortalController.js:11-30; src/service/PortalManagementService/PortalManagementService.Types.d.ts:173 |
| GET | `/portals/{portalId}` | ein Portal | keine | `Portal` | src/service/PortalManagementService/PortalController.js:34-43 |
| GET | `/portals/{portalId}/checkAuthentication` | ist das Portal authentifiziert | keine | untypisiert | src/service/PortalManagementService/PortalController.js:84-95 |
| GET | `/portalTypes?companyMarket=` | Portaltypen mit Beschriftung und Logo | `companyMarket` optional: `germany`, `franchise` | `[{ captions, logo, portalType }]` | src/service/PortalManagementService/PortalController.js:144-158; src/service/CompanyService/CompanyService.Types.d.ts:143-146; src/service/PortalManagementService/PortalManagementService.Types.d.ts:206-212 |
| GET | `/predefinedPortals?companyMarket=` | vordefinierte Portale (FTP-Vorlagen) | Query | `PredefinedPortal[]` | src/service/PortalManagementService/PortalController.js:161-175; src/service/PortalManagementService/PortalManagementService.Types.d.ts:175-184 |
| POST | `/portals/create/{portalType}` | Portal anlegen | Body `Portal` ohne `id`, `fullUpdate`, `authenticated`, `portalType`; `Content-Type: application/json` | `Portal` | src/service/PortalManagementService/PortalController.js:110-124; src/service/PortalManagementService/PortalController.d.ts:40 |
| PATCH | `/portals/{portalId}` | Portal ändern (FTP-Port Standard 21) | Body `Portal` | `Portal` | src/service/PortalManagementService/PortalController.js:58-69 |
| POST | `/portals/{portalId}/authenticate` | Portal authentifizieren (OAuth-Start) | Body `{ callbackUrl }` | untypisiert | src/service/PortalManagementService/PortalController.js:71-82; src/service/PortalManagementService/PortalManagementService.Types.d.ts:10-12 |
| GET | `/portals/is24/authenticate/{portalId}/callback?oauth_verifier=&oauth_token=&state=` | IS24-OAuth-Rückruf | Query | untypisiert | src/service/PortalManagementService/PortalController.js:127-142 |
| DELETE | `/portals/{portalId}` bzw. `/portals/{portalId}/force` | Portal löschen (force ohne Offline-Schaltung) | keine | Statuscodes 200, 404 (noch veröffentlichte Objekte), 500 | src/service/PortalManagementService/PortalController.js:45-56, :97-108; src/service/PortalManagementService/PortalManagementService.Types.d.ts:5-9 |

`Portal = { id, portalType, authenticated, fullUpdate, name?, description?, companyId?, ftpServer?, ftpPort?, ftpFolder?, ftpConnectionType? ('FTP' | 'SFTP' | 'FTPS'), loginName?, password?, portalKey?, contingent?, vendor?, logo?, website?, portalAdditionalSettings?, _acps?, _metadata? }`. Quelle: src/service/PortalManagementService/PortalManagementService.Types.d.ts:123-146, :174

**Portal-Objekt-Zuordnung (PortalEstateController)**

| Methode | Pfad | Zweck | Request | Response | Quelle |
|---|---|---|---|---|---|
| GET | `/estates/{estateId}/portals` | auf welchen Portalen ist ein Objekt | keine | `[{ portalId, entityId?, lastUpdate?, onlineSince?, showAddress?, channels, publishBlockedUntil?, externalId? }]` | src/service/PortalManagementService/PortalEstateController.js:11-24; src/service/PortalManagementService/PortalManagementService.Types.d.ts:147-159 |
| POST | `/estates/portals` | dasselbe für mehrere Objekte | Body `string[]` (Estate-IDs) | `{ [estateId]: PortalPublishInformation[] }` | src/service/PortalManagementService/PortalEstateController.js:26-38; src/service/PortalManagementService/PortalManagementService.Types.d.ts:160-162 |
| GET | `/portals/{portalId}/estates` | alle veröffentlichten Objekte eines Portals | keine | `PortalEstate[]` | src/service/PortalManagementService/PortalEstateController.js:40-52 |
| GET | `/portals/{portalId}/estates/{estateId}` | Eintrag eines Objekts auf einem Portal | keine | untypisiert | src/service/PortalManagementService/PortalEstateController.js:54-67 |
| GET | `/portals/{portalId}/estates/count` | Anzahl veröffentlichter Objekte | keine | `number` | src/service/PortalManagementService/PortalEstateController.js:99-108 |
| POST | `/portals/{portalId}/estates/{estateId}` | Einstellungen (Adresse zeigen, externe ID, Kanäle) setzen | Body `PortalEstateSettings` | untypisiert | src/service/PortalManagementService/PortalEstateController.js:69-83; src/service/PortalManagementService/PortalManagementService.Types.d.ts:163-172 |
| GET | `/portals/estates/{estateId}` | Einstellungen aller Portale eines Objekts | keine | `PortalEstateSettings[]` | src/service/PortalManagementService/PortalEstateController.js:110-122 |
| DELETE | `/portals/{portalId}/estates/{entityId}` | Verknüpfung Objekt zu Portal lösen | keine | untypisiert | src/service/PortalManagementService/PortalEstateController.js:85-97 |
| GET | `/estates/{estateId}/publishing-history` | wurde das Objekt schon einmal veröffentlicht | keine | `boolean` | src/service/PortalManagementService/PortalEstateController.js:124-136; src/service/PortalManagementService/PortalEstateController.d.ts:50 |
| GET | `/portalType/{type}/estate/{estateId}` | letzte Veröffentlichung je Portaltyp | keine | `PortalEstate` | src/service/PortalManagementService/PortalEstateController.js:138-150 |

`PortalEstateSettings = { id, portalId, schemaId?, schemaName, entityId, externalId, showAddress, publishChannels?: ('SCOUT' | 'HOMEPAGE' | 'PROJECT')[] }`. Quelle: src/service/PortalManagementService/PortalManagementService.Types.d.ts:163-172, :118

**Veröffentlichen (PublishController)**

| Methode | Pfad | Zweck | Request | Response | Quelle |
|---|---|---|---|---|---|
| POST | `/publish` | ein oder mehrere Objekte auf ein Portal ONLINE oder OFFLINE schalten | Body `PublishRequest`; `publishType` wird auf `MANUAL` gesetzt, wenn leer | `PublishResponse` oder leer (wenn Kafka aktiv und Portaltyp nicht WordPress) | src/service/PortalManagementService/PublishController.js:12-29; src/service/PortalManagementService/PublishController.d.ts:7-12 |
| POST | `/publish/bulk` | ein Objekt auf mehrere Portale | Body `BulkPublishRequest` | `PublishBulkResponse` | src/service/PortalManagementService/PublishController.js:31-43; src/service/PortalManagementService/PublishController.d.ts:13-17 |

Request-Typen (Quelle: src/service/PortalManagementService/PortalManagementService.Types.d.ts):

```
PublishRequest = {
  portalId: string,
  entries: PublishRequestEntry[],
  portalType?: 'IS24' | 'OPENIMMO' | 'WORDPRESS',
  publishType?: 'MANUAL' | 'AUTOMATIC'
}                                                   // Zeilen 57-67

PublishRequestEntry = {
  entityId: string,
  targetStatus: 'OFFLINE' | 'ONLINE' | 'DELETE',
  externalId?: string,
  entityLocation?: string,
  publishChannels?: [{ channelIdentifier, type: 'SCOUT' | 'HOMEPAGE' | 'PROJECT' }],
  schema?: string,
  schemaId?: string,
  showAddress?: boolean,
  localHeroBookingDetails?: { ... }
}                                                   // Zeilen 101-122, Status Zeile 38

BulkPublishRequest = {
  companyId?, userId?,
  publishRequestEntry: PublishRequestEntry,
  publishPortalRequestEntries: [{ portalId, portalType? }]
}                                                   // Zeilen 13-22
```

Response-Typen (gleiche Datei):

```
PublishResponse = {
  companyId, userId,
  errors: PublishResponseEntry[],
  warnings: PublishResponseEntry[],
  successFullyTransfered: PublishResponseEntry[],
  successfullyScheduled: PublishResponseEntry[],
  portalSyncMode: 'FULL_SYNC' | 'PART_SYNC',
  publishType
}                                                   // Zeilen 68-77, 63

PublishResponseEntry = {
  entityId, externalId, portalId, schema, schemaId, targetStatus, timestamp, usedIdForPortal, showAddress,
  messages: string[],
  detailedMessages: [{ originalMessage, validationError: { translatedMessage, type } | null }],
  publishChannels, localHeroBookingDetails
}                                                   // Zeilen 78-100

PublishBulkResponse = {
  entityId, schemaId, schema, targetStatus,
  portalsWithoutAccessRights: string[],
  succeededPublications: [{ portalId, portalType?, errorResponseMessage? }],
  failedPublications: [...], scheduledPublications: [...]
}                                                   // Zeilen 23-37
```

**Company-weite Objektattribute (CompanyEstateAttributesController)**

Freies Schlüssel-Wert-Register je Objekt (`Record<string, any>`), gespeichert im Portal-Management-Service. Quelle: src/service/PortalManagementService/CompanyEstateAttributesController.js:7; src/service/PortalManagementService/PortalManagementService.Types.d.ts:216

| Methode | Pfad | Zweck | Request | Response | Quelle |
|---|---|---|---|---|---|
| POST | `/companies/estates/{entityId}/attributes/{key}` | ein Attribut setzen | Body: beliebiger Wert | Attribute | src/service/PortalManagementService/CompanyEstateAttributesController.js:20-29 |
| POST | `/companies/estates/{entityId}/attributes` | mehrere setzen | Body `Record<string, any>` | Attribute | src/service/PortalManagementService/CompanyEstateAttributesController.js:35-44 |
| GET | `/companies/estates/{entityId}/attributes/{key}` | ein Attribut lesen | keine | Attribute | src/service/PortalManagementService/CompanyEstateAttributesController.js:50-59 |
| GET | `/companies/estates/{entityId}/attributes` | alle lesen | keine | Attribute | src/service/PortalManagementService/CompanyEstateAttributesController.js:64-73 |
| DELETE | `/companies/estates/{entityId}/attributes/{key}` bzw. `/attributes` | löschen | keine | gelöschte Attribute | src/service/PortalManagementService/CompanyEstateAttributesController.js:80-104 |

**Projekte und Local Hero (ProjectsController, ProjectsEstateController, LocalHeroController)**

Für den Connector voraussichtlich ohne Bedeutung, der Vollständigkeit halber aufgeführt.

| Methode | Pfad | Zweck | Request | Response | Quelle |
|---|---|---|---|---|---|
| POST | `/projects/{projectId}/publish` bzw. `/unpublish` | Bauträgerprojekt veröffentlichen oder offline nehmen | keine | `{ targetStatus, warnings, errors }` | src/service/PortalManagementService/ProjectsController.js:15-40; src/service/PortalManagementService/PortalManagementService.Types.d.ts:201-205 |
| GET | `/estates/{estateId}/projects` | Projekte eines Objekts | keine | `{ assignedProjects, importedProjects }` | src/service/PortalManagementService/ProjectsEstateController.js:15-26; src/service/PortalManagementService/PortalManagementService.Types.d.ts:197-200 |
| POST | `/local-hero/slot-availability` | IS24-Local-Hero-Verfügbarkeit prüfen | Body `{ companyId, entityId, portalId, schemaName, location }` | `{ slotAvailable, alredayPublished, geoPath, httpStatus, message }` | src/service/PortalManagementService/LocalHeroController.js:14-25; src/service/PortalManagementService/PortalManagementService.Types.d.ts:217-230 |
| GET | `/local-hero/estates/{entityId}` | Local-Hero-Buchung eines Objekts | keine | `{ portalId, bookedUntil }` | src/service/PortalManagementService/LocalHeroController.js:27-38; src/service/PortalManagementService/PortalManagementService.Types.d.ts:43-46 |

### 5.5 IS24-Publish-Service (`is24-publish-service`)

| Methode | Pfad | Zweck | Request | Response | Quelle |
|---|---|---|---|---|---|
| GET | `/statistics/estates/{estateId}?startDate=&toDate=` | IS24-Statistik eines Objekts | Query | `{ dailyStatistics: [{ date, exposeHits }], externalId, fromDate, toDate, totalStatistics: { clicksHomepage, clicksSendUrl, emailContacts, exposeHits, onShortList } }` | src/service/IS24PublishService/IS24PublishController.js:11-31; src/service/IS24PublishService/IS24Publish.Types.d.ts:5-26 |
| GET | `/portals/{portalId}/projects` | IS24-Projekte eines Portals | keine | `[{ channelId, name }]` | src/service/IS24PublishService/IS24PublishController.js:33-45; src/service/IS24PublishService/IS24Publish.Types.d.ts:12-15 |
| GET | `/OTP/estate/{estateId}/portal/{portalId}` | URL für IS24-Zusatzprodukte | keine | `{ url }` | src/service/IS24PublishService/IS24PublishController.js:47-60; src/service/IS24PublishService/IS24Publish.Types.d.ts:2-4 |
| POST | `/portals/{portalId}/project-proposals` | Projektvorschlag anlegen | Body `IS24ProjectProposalRequest` | untypisiert | src/service/IS24PublishService/IS24PublishController.js:62-74; src/service/IS24PublishService/IS24Publish.Types.d.ts:27-38 |
| GET | `/project-proposals/{projectId}` | Projektvorschlag lesen | keine | `{ projectId, projectName, numberOfHousingUnits, startDate, status }` | src/service/IS24PublishService/IS24PublishController.js:76-88; src/service/IS24PublishService/IS24Publish.Types.d.ts:39-45 |
| GET | `/portal/{portalId}/estate/{entityId}/description` | Objektbeschreibung (Retresco) | keine | untypisiert | src/service/IS24PublishService/IS24PublishController.js:90-103 |
| GET | `/portal/{portalId}/estate/{entityId}/locationText` | Lagetext (Retresco) | keine | untypisiert | src/service/IS24PublishService/IS24PublishController.js:105-118 |
| DELETE | `/portal/{portalId}/estate/{estateId}` | Objekt bei IS24 löschen (IS24RealEstateController) | keine | untypisiert | src/service/IS24PublishService/IS24RealEstateController.js:16-26; src/service/IS24PublishService/IS24RealEstateController.d.ts:9 |

Fehlerkörper dieses Services (einzige typisierte Fehlerform im untersuchten Umfang):

```
ErrorResponse = { type, status, description, path, additionalInfo: { errorMessages: [{ code, original, translated }] } }
```

Quelle: src/service/IS24PublishService/IS24Publish.Types.d.ts:46-59

Das eigentliche Veröffentlichen auf IS24 läuft nicht über diesen Service, sondern über `POST /publish` im Portal-Management-Service (5.4). Quelle: kein Publish-Endpunkt in src/service/IS24PublishService/IS24PublishController.js:1-120 und src/service/IS24PublishService/IS24RealEstateController.js:1-30 (dort nur DELETE)

### 5.6 Search-Service (`search-service`)

| Methode | Pfad | Zweck | Request | Response | Quelle |
|---|---|---|---|---|---|
| POST | `/schemas/{schemaName}?page=1&size=&withCount=&includeSystemFields=` | Entitäten suchen | Body Flowdsl, `Content-Type: application/json`; `includeSystemFields=false` nur senden, wenn Systemfelder nicht gewünscht (Standard im Backend ist true) | `{ entries: Entity[], totalCount, page, offset, size }` | src/service/SearchService/SearchService.js:119-138, :297-314; src/types/common/Responses.d.ts:9-15 |
| POST | `/schemas/{schemaName}?offset=0&size=20&withCount=true` | Suche mit Offset statt Seite | Body Flowdsl | `PagedResponse<Entity>` | src/service/SearchService/SearchService.js:171-186 |
| POST | `/schemas/{index}/count?groupBy=` | Trefferanzahl | Body Flowdsl | untypisiert (Zahl) | src/service/SearchService/SearchService.js:194-210 |
| POST | `/schemas/{index}/count?groupBy=a,b&treatingBlankStringValuesAsNull=` | Gruppierte Zählung | Body Flowdsl, v2 | `{ totalCount, countPerGroup: [{ count, values }] }` | src/service/SearchService/SearchService.js:219-238; src/service/SearchService/SearchService.Types.d.ts:2-11 |
| GET, POST, PUT, DELETE | `/search`, `/search/{searchId}` | gespeicherte Suchen verwalten | | untypisiert | src/service/SearchService/SearchService.js:23-87 |
| POST | `/saved-searches?page=&size=&withCount=` | gespeicherte Suchen durchsuchen | Body Flowdsl | `PagedResponse<Entity>` | src/service/SearchService/SearchService.js:147-162 |
| POST | `/internal/schemas/{index}/count?companyId=&withAclGroups=` | interne Zählung (Pfadpräfix `internal`, extern voraussichtlich nicht erreichbar) | Body Flowdsl | untypisiert | src/service/SearchService/SearchService.js:246-261 |

`{schemaName}` bzw. `{index}` kann laut SDK-Kommentar auch der Name einer Gruppe sein ("index - schema name"). Quelle: src/service/SearchService/SearchService.d.ts:69. `findSchemaName` sucht im Index (z. B. Gruppe) und liest den konkreten Schemanamen aus `_metadata.schema`. Quelle: src/service/SearchService/SearchService.js:316-336

**Flowdsl, soweit aus dem SDK ablesbar**

Die Abfragesprache kommt aus dem Paket `@flowfact/node-flowdsl` 3.0.0, das nicht im Tarball enthalten ist. Quelle: package.json:17. Aus dem SDK-Code sind folgende Bedingungsobjekte wörtlich belegt. Quelle: src/service/SearchService/SearchService.js:338-370

```
{ "type": "HASFIELDWITHVALUE", "field": "<feldname>", "value": "<wert>", "operator": "LIKE" }
{ "type": "ENTITYID", "values": ["<entityId>"] }
{ "type": "OR", "conditions": [ ... ] }
```

Zusätzlich sichtbar: Builder-Aufrufe `target('ENTITY')`, `distinct(false)`, `withCondition([...])`, `fetch([...feldnamen])`, `condition.hasEntityIds(entityId)`. Quelle: src/service/SearchService/SearchService.js:322-325, :339-341, :357-366. Die exakten JSON-Schlüsselnamen, die der Builder daraus erzeugt (z. B. ob `target`, `distinct`, `conditions`, `fetch` auf oberster Ebene heißen), sind im Tarball nicht enthalten und am echten Konto zu prüfen (Abschnitt 9). Der Typ `HasFieldWithValueCondition` wird im Entity-Service referenziert. Quelle: src/service/EntityService/EntityService.Types.d.ts:1, :60

### 5.7 Vermarktungsphasen und Objektphasen

**property-marketing-phase-service (PhasesController)**

| Methode | Pfad | Zweck | Request | Response | Quelle |
|---|---|---|---|---|---|
| GET | `/{schemaId}/phases` | Phasendefinition eines Schemas | keine | `{ phases: [{ id, name, captions, position, steps: [{ id, captions, position, completed: { value, timestamp }, widgets }], widgets }], transactionValidations, widgets }` | src/service/PropertyMarketingPhaseService/PhasesController.js:15-24; src/service/PropertyMarketingPhaseService/PropertyMarketingPhaseService.Types.d.ts:22-44 |
| GET | `/{schemaId}/phases/{phaseName}` | eine Phase | keine | `Phase` | src/service/PropertyMarketingPhaseService/PhasesController.js:30-39 |
| GET | `/{schemaId}/{entityId}/currentPhase` | aktuelle Phase eines Objekts | keine | untypisiert | src/service/PropertyMarketingPhaseService/PhasesController.js:104-113 |
| GET | `/{schemaId}/{entityId}/phases` | alle Phasen eines Objekts | keine | untypisiert | src/service/PropertyMarketingPhaseService/PhasesController.js:209-218 |
| POST | `/{schemaId}/{entityId}/switchToStep` | Schritt wechseln (mit Validierung) | Body `{ stepId, source }`, `source` ist `KANBAN` oder `LIFECYCLE` | Status OK oder REJECTED plus Widgets | src/service/PropertyMarketingPhaseService/PhasesController.js:134-151 |
| POST | `/{schemaId}/{entityId}/updateStep` | Schritt als erledigt markieren | Body `{ stepId, completed }` | untypisiert | src/service/PropertyMarketingPhaseService/PhasesController.js:121-132 |
| POST | `/validateStep/stepId/{stepId}/schemaId/{schemaId}/entityId/{entityId}` | Schritt validieren | keine | untypisiert | src/service/PropertyMarketingPhaseService/PhasesController.js:225-234 |
| GET | `/phases/{phaseName}/entities?archived=true&inactive=true&page=1&size=50` | Objekte in einer Phase | Query | untypisiert | src/service/PropertyMarketingPhaseService/PhasesController.js:48-70 |
| GET | `/phases/{phaseName}/entities/{schemaId}?...` | dito je Schema | Query | untypisiert | src/service/PropertyMarketingPhaseService/PhasesController.js:78-100 |
| POST | `/phases` | aktuelle Phase mehrerer Objekte | Body Liste von Entitäten | untypisiert | src/service/PropertyMarketingPhaseService/PhasesController.js:194-203 |
| GET | `/phases/stats?archived=&inactive=&size=50` | Statistik | Query | untypisiert | src/service/PropertyMarketingPhaseService/PhasesController.js:158-176 |
| GET | `/calculateTotalCommissionForAllPhases` | Provisionssumme über alle Phasen | keine | untypisiert | src/service/PropertyMarketingPhaseService/PhasesController.js:180-189 |
| DELETE | `/steps/{schemaId}/{entityId}` | Phaseninformationen eines Objekts löschen | keine | untypisiert | src/service/PropertyMarketingPhaseService/PhasesController.js:240-249 |
| GET, POST | `/phaseconfigurations` | Phasenkonfigurationen lesen bzw. anlegen oder ändern | Body `{ id, schemaName, timestamp, phaseConfiguration }` | untypisiert | src/service/PropertyMarketingPhaseService/PhasesController.js:253-276; src/service/PropertyMarketingPhaseService/PropertyMarketingPhaseService.Types.d.ts:3-8 |
| DELETE | `/phaseconfigurations/{id}` | eigene Phasenkonfiguration löschen | keine | untypisiert | src/service/PropertyMarketingPhaseService/PhasesController.js:281-290 |

Phasennamen: `acquisition`, `preparation`, `marketing`, `closing`, `after_sales`. Quelle: src/service/PropertyMarketingPhaseService/PropertyMarketingPhaseService.Types.d.ts:14

**object-phases-lambda (ObjectPhasesController)**

| Methode | Pfad | Zweck | Request | Response | Quelle |
|---|---|---|---|---|---|
| POST | `/successful-deal` | Abschluss verbuchen, Objektphase setzen, andere Deals stornieren | Body `{ estateId, schema, updateObjectPhase, cancelEstateDeals }` | `{ objectPhaseUpdated, cancelledDeals }` | src/service/ObjectPhaseService/ObjectPhasesController.js:18-31; src/service/ObjectPhaseService/DealController.Types.d.ts:1-4 |
| POST | `/successful-object-phase` | Deal auf erfolgreich setzen | Body `{ estateId, contactId, updateSuccessfulDeal, cancelOtherDeals }` | `{ successfulDealUpdated, cancelledDeals }` | src/service/ObjectPhaseService/ObjectPhasesController.js:39-52; src/service/ObjectPhaseService/DealController.Types.d.ts:5-8 |
| POST | `/lost-object-phase` | Objekt als verloren markieren | Body `{ estateId }` | `{ cancelledDeals }` | src/service/ObjectPhaseService/ObjectPhasesController.js:57-65; src/service/ObjectPhaseService/DealController.Types.d.ts:9-11 |

Ergänzend trägt die Estate-Entität selbst die Felder `status` (`active`, `inactive`, `archived`) und `objectPhase` (string). Quelle: src/types/entities/estates/Estate.d.ts:229-230, :4-8

### 5.8 Benutzer, Company, Token

**user-service**

| Methode | Pfad | Zweck | Request | Response | Quelle |
|---|---|---|---|---|---|
| GET | `/users/currentUser` | aktuell angemeldeter Benutzer (liefert u. a. `companyId`) | keine; der Controller sendet `x-ff-version: 2` (Konstruktor setzt Version `'2'`) | `User` | src/service/UserService/UsersV2Controller.js:9, :26-38; src/service/UserService/UserService.Types.d.ts:46-75 |
| GET | `/users?userType=API,USER` | Benutzer der eigenen Company, Filter nach Typ (kommagetrennt) | Query | `User[]` | src/service/UserService/UsersController.js:11-29 |
| GET | `/public/cognito-users/usernames?name=` | Cognito-Benutzername zu Login ermitteln | Query, v2 | `{ identifier, identifiersOfMatchingAliases, ssoInformation }`; 404 nicht gefunden, 409 nicht eindeutig | src/service/UserService/PublicController.js:11-33; src/service/UserService/UserService.Types.d.ts:41-45, :23-26 |
| POST | `/public/sso/token` | SSO-Code gegen Tokens tauschen | Body `{ code, clientId, redirectURI }` | `SSOTokenResponse` | src/service/UserService/PublicController.js:75-94 |
| DELETE | `/users/{userId}/password` | Passwort zurücksetzen (Mail) | keine | untypisiert | src/service/UserService/UsersController.js:98-111 |

`User = { id, createdAt, active, loginRelatedMailAddress, businessMailAddress, companyId?, firstname?, lastname?, loginName?, roles?: ('USER' | 'ADMIN' | 'ACCOUNT_MANAGER' | 'USER_LIGHT' | 'USER_AGENT')[], type?: UserType, language?, timezone?, ... }`. Quelle: src/service/UserService/UserService.Types.d.ts:2-14, :46-75

**company-service**

| Methode | Pfad | Zweck | Request | Response | Quelle |
|---|---|---|---|---|---|
| GET | `/company/{companyId}` | Company lesen | ID URL-kodiert | `Company` | src/service/CompanyService/CompanyController.js:41-50; src/service/CompanyService/CompanyService.Types.d.ts:66-112 |
| PUT | `/company` | Company ändern | Body `Company` | `Company` | src/service/CompanyService/CompanyController.js:30-39 |
| POST | `/company/logo` | Logo hochladen | `multipart/form-data` | `S3File` | src/service/CompanyService/CompanyController.js:52-65 |

`Company` enthält u. a. `id, companyName, companyStreet, companyPostcode, companyCity, companyPhoneInfo, companyMailInfo, companyUrl, companyHrb, companyHrbPlace, companyMarket ('germany' | 'franchise')`. Quelle: src/service/CompanyService/CompanyService.Types.d.ts:66-112, :143-146

**admin-token-service**: siehe Abschnitt 3.4.

---

## 6. Ablauf Veröffentlichung auf Portalen (rekonstruiert)

Der folgende Ablauf ist aus den Endpunkten in Abschnitt 5.4 zusammengesetzt. Kein einzelner SDK-Aufruf bildet den Gesamtprozess ab; die Reihenfolge ist eine Empfehlung.

1. **Portale ermitteln**
   `GET portal-management-service/portals?ignoreInactivePortals=true` liefert die konfigurierten Portale mit `id`, `portalType` und `authenticated`. Quelle: src/service/PortalManagementService/PortalController.js:15-30; Typ src/service/PortalManagementService/PortalManagementService.Types.d.ts:123-146
   Nur Portale mit `authenticated = true` sind sinnvoll ansprechbar. Für IS24 kann zusätzlich `GET /portals/{portalId}/checkAuthentication` geprüft werden. Quelle: src/service/PortalManagementService/PortalController.js:86-95

2. **Portalanmeldung ist Sache der FLOWFACT-Oberfläche**
   Das Anlegen (`POST /portals/create/{portalType}`) und die OAuth-Authentifizierung (`POST /portals/{portalId}/authenticate` mit `callbackUrl`, IS24-Callback) sind im SDK vorhanden, setzen aber einen Browser-Redirect voraus. Quelle: src/service/PortalManagementService/PortalController.js:71-82, :110-142. Der Connector sollte Portale nur lesen, nicht anlegen.

3. **Objekt vorbereiten**
   Estate anlegen oder aktualisieren (5.1), Bilder hochladen und zuordnen (Abschnitt 8). Optional Portaleinstellungen je Objekt setzen: `POST /portals/{portalId}/estates/{estateId}` mit `{ showAddress, externalId, publishChannels, schemaName, entityId, portalId }`. Quelle: src/service/PortalManagementService/PortalEstateController.js:74-83; src/service/PortalManagementService/PortalManagementService.Types.d.ts:163-172

4. **Ist-Zustand lesen (Idempotenz)**
   `GET /estates/{estateId}/portals` zeigt, auf welchen Portalen das Objekt bereits liegt (`portalId`, `onlineSince`, `lastUpdate`, `externalId`, `publishBlockedUntil`). Quelle: src/service/PortalManagementService/PortalEstateController.js:15-24; Typ Zeilen 147-159. Vor einem erneuten `ONLINE` prüfen, ob `publishBlockedUntil` in der Zukunft liegt (Bedeutung des Feldes am Konto verifizieren).

5. **Veröffentlichen**
   `POST /publish` mit
   ```json
   {
     "portalId": "<portalId>",
     "portalType": "IS24",
     "publishType": "MANUAL",
     "entries": [
       { "entityId": "<estateId>", "schema": "<estateSchemaName>", "targetStatus": "ONLINE", "showAddress": true }
     ]
   }
   ```
   Quelle: src/service/PortalManagementService/PublishController.js:17-29; src/service/PortalManagementService/PortalManagementService.Types.d.ts:57-62, :101-117
   Für mehrere Portale in einem Aufruf: `POST /publish/bulk` mit `publishRequestEntry` und `publishPortalRequestEntries`. Quelle: src/service/PortalManagementService/PublishController.js:34-43; Typ Zeilen 13-22

6. **Antwort auswerten**
   Wenn ein Body zurückkommt: `errors`, `warnings`, `successFullyTransfered`, `successfullyScheduled` auswerten, je Eintrag `messages` und `detailedMessages[].validationError.translatedMessage`. Quelle: src/service/PortalManagementService/PortalManagementService.Types.d.ts:68-100
   Achtung: Laut SDK-Kommentar kommt bei aktiviertem Kafka und Portaltyp ungleich WordPress **kein** Body zurück (asynchrone Verarbeitung). Quelle: src/service/PortalManagementService/PublishController.js:15. Der Connector muss also einen leeren 2xx-Body als "angenommen, Ergebnis später" behandeln und den Zustand über Schritt 4 nachlesen.

7. **Offline nehmen**
   Gleicher Aufruf mit `targetStatus: "OFFLINE"` (oder `DELETE`). Quelle: src/service/PortalManagementService/PortalManagementService.Types.d.ts:38. Zum Lösen der Verknüpfung ohne Publish-Vorgang: `DELETE /portals/{portalId}/estates/{entityId}`. Quelle: src/service/PortalManagementService/PortalEstateController.js:89-97

8. **Nachträglich prüfen**
   `GET /estates/{estateId}/publishing-history` (boolean, jemals veröffentlicht) und `GET /portalType/IS24/estate/{estateId}` (letzte Veröffentlichung). Quelle: src/service/PortalManagementService/PortalEstateController.js:127-136, :142-150. IS24-Statistiken über 5.5.

---

## 7. Dublettenschutz: bestehende Entität wiederfinden

**Ziel:** Vor jeder Anlage prüfen, ob zur eigenen Vorgangsnummer (externe Referenz) bereits ein Estate existiert.

**Empfohlener Weg (bestätigte Bausteine)**

1. Externe Referenz in einem Textfeld des Estates speichern. Der SDK-Typ kennt das Feld `identifier` (string). Quelle: src/types/entities/estates/Estate.d.ts:215. Ob dieses Feld im Konto frei belegbar ist oder von FLOWFACT automatisch vergeben wird (vgl. Präfix-Endpunkte `GET /prefixes`, Quelle: src/service/EntityService/EntityService.js:456-462), ist zu prüfen. Alternativ ein eigenes Schemafeld anlegen lassen und dessen Namen aus `GET /v2/schemas/{schema}` lesen (5.2).

2. Suche über den Search-Service:
   `POST search-service/schemas/{estateSchemaOderGruppe}?page=1&size=2&withCount=true` mit `Content-Type: application/json` und einer Flowdsl-Bedingung. Quelle: src/service/SearchService/SearchService.js:122-138
   Bestätigtes Bedingungsobjekt: `{ "type": "HASFIELDWITHVALUE", "field": "identifier", "value": "HVM-2026-000123", "operator": "LIKE" }`. Quelle: src/service/SearchService/SearchService.js:351-356. Als Index kann die Gruppe `estates` dienen, der konkrete Schemaname steht dann in `entries[i]._metadata.schema`. Quelle: src/service/SearchService/SearchService.js:316-336; src/service/EntityService/EntityService.Types.d.ts:35-46
   Antwort: `{ entries, totalCount, page, offset, size }`. Quelle: src/types/common/Responses.d.ts:9-15

3. Auswertung: `totalCount = 0` heißt anlegen. `totalCount = 1` heißt aktualisieren über `PATCH /schemas/{_metadata.schema}/entities/{id}` (5.1). `totalCount > 1` heißt Konflikt, manuell klären. Da das SDK nur den Operator `LIKE` belegt, muss der Connector die Treffer zusätzlich exakt auf Gleichheit des Feldwerts prüfen. Ein Exakt-Operator ist am Konto zu verifizieren (Abschnitt 9).

**Alternative Suchen**

- Entity-Service: `POST entity-service/search/schemas/{index}?page=&size=&withCount=` mit `x-ff-version: 2` und Flowdsl-Body. Quelle: src/service/EntityService/EntityService.js:144-160
- Trefferzählung ohne Daten: `POST search-service/schemas/{index}/count`. Quelle: src/service/SearchService/SearchService.js:194-210
- Direkter Zugriff, wenn die FLOWFACT-ID bereits lokal gespeichert ist: `GET entity-service/schemas/{schema}/entities/{id}`. Quelle: src/service/EntityService/EntityService.js:337-342. Der Connector sollte die zurückgegebene FLOWFACT-`id` nach der Anlage persistieren, damit die Suche nur der Fallback ist.

**Zusätzliche Ablage der Referenz**

Das Attribut-Register `POST portal-management-service/companies/estates/{entityId}/attributes/{key}` erlaubt beliebige Schlüssel-Wert-Paare je Objekt. Quelle: src/service/PortalManagementService/CompanyEstateAttributesController.js:20-29. Es ist aber nur per Entity-ID abrufbar, also nicht für die Rückwärtssuche geeignet.

**Papierkorb beachten**

Gelöschte Entitäten liegen im Papierkorb (`GET entity-service/recovery/entities?schema=`). Quelle: src/service/EntityService/EntityService.js:492-506. Ob die Suche gelöschte Objekte ausschließt, ist zu prüfen.

---

## 8. Bilder und Dokumente

**Datenmodell**

- Jedes Bild oder Dokument ist ein `MultimediaItem` mit numerischer `id`, `contentCategory` (`IMAGE`, `DOCUMENT`, `LINK`, `VIDEO`), `fileReference`, `fileName`, `contentType`, `fileSize`, optional `title`, `description`. Quelle: src/service/MultimediaService/MultimediaService.Types.d.ts:2-15
- Items werden Alben zugeordnet. Ein Album gehört zu einem Schema und hat Kategorien mit `sorting`, `maxItems` und erlaubten Inhaltsarten. Quelle: src/service/MultimediaService/MultimediaService.Types.d.ts:30-48
- Eine Zuordnung trägt `sorting` (Reihenfolge). Quelle: src/service/MultimediaService/MultimediaService.Types.d.ts:26-29

**Ablauf Upload (rekonstruiert aus den bestätigten Endpunkten)**

1. Alben und Kategorien des Estate-Schemas lesen: `GET multimedia-service/albums/schemas/{schemaName}`. Quelle: src/service/MultimediaService/AlbumsController.js:15-24. Daraus `albumName` und Kategorienamen für Bilder bzw. Dokumente entnehmen (die Namen selbst sind kontospezifisch, Abschnitt 9).

2. Vorsignierte Upload-URL anfordern: `GET multimedia-service/items/schemas/{schemaName}/entities/{entityId}/presigned-url?contentType=image/jpeg&fileName=foto1.jpg&fileSize=<bytes>` liefert `{ presignedUrl, itemLink }`. Quelle: src/service/MultimediaService/ItemsController.js:128-142; src/service/MultimediaService/MultimediaService.Types.d.ts:52-55. Achtung: Das SDK verwendet in diesem Pfad hart den Schemanamen `house_purchase`. Ob der Pfad für andere Estate-Schemata den jeweiligen Namen erwartet, ist zu prüfen.

3. Binärdatei an `presignedUrl` übertragen. Dieser Schritt ist im SDK nicht enthalten (der Browser lädt direkt zu S3). Methode und Header sind am Konto zu verifizieren (Abschnitt 9).

4. Item registrieren: `POST multimedia-service/items/schemas/{schemaName}/entities/{entityId}` mit
   ```json
   {
     "contentType": "image/jpeg",
     "fileName": "foto1.jpg",
     "fileSize": 123456,
     "itemLink": "<itemLink aus Schritt 2>",
     "title": "Wohnzimmer",
     "albumAssignments": [ { "albumName": "<albumName>", "categories": ["<kategorie>"] } ]
   }
   ```
   Antwort `{ multimediaItem }`. Quelle: src/service/MultimediaService/ItemsController.js:149-167; src/service/MultimediaService/MultimediaService.Types.d.ts:56-65, :49-51

5. Reihenfolge und Hauptbild: Die Reihenfolge wird über `sorting` je Zuordnung gesetzt: `PUT multimedia-service/assigned/schemas/{schemaName}/entities/{entityId}?albumName=<albumName>&short=false` mit `{ "assignments": { "<kategorie>": [ { "multimedia": <MultimediaItem>, "sorting": 0 }, ... ] } }`. Quelle: src/service/MultimediaService/AlbumAssignmentController.js:64-80. Ein explizites Feld "Hauptbild" gibt es im SDK nicht; naheliegend ist die Position `sorting = 0` in der Bilder-Kategorie. Am Konto verifizieren (Abschnitt 9).

6. Kontrolle: `GET multimedia-service/items/entities/{entityId}?contentCategory=IMAGE` und `GET /assigned/schemas/{schemaName}/entities/{entityId}?albumName=&short=true`. Quelle: src/service/MultimediaService/ItemsController.js:89-103; src/service/MultimediaService/AlbumAssignmentController.js:26-39

**Alternativer Direkt-Upload (deprecated)**

`POST multimedia-service/upload/{entityId}` als `multipart/form-data` mit Feld `file`. Im SDK als `@Deprecated` markiert, Antwort untypisiert. Quelle: src/service/MultimediaService/ItemsController.js:31-56. Nur als Fallback, falls der vorsignierte Weg extern nicht funktioniert.

**Dokumente**

Gleicher Ablauf mit `contentType` z. B. `application/pdf`; die Kategorie muss `DOCUMENT` in `allowedContentCategories` zulassen. Quelle: src/service/MultimediaService/MultimediaService.Types.d.ts:30-37

**Löschen und Ersetzen**

- `DELETE /items/{mediaItemId}` löscht Item und alle Zuordnungen. Quelle: src/service/MultimediaService/ItemsController.js:170-182
- `POST /items/{itemId}/file` (multipart) ersetzt die Binärdatei und liefert `{ url, etag }`. Quelle: src/service/MultimediaService/ItemsController.js:11-30
- `PATCH /items/{mediaItemId}` mit JSON-Patch ändert Metadaten wie `title`. Quelle: src/service/MultimediaService/ItemsController.js:235-244

---

## 9. Offen, am echten Konto zu verifizieren

Nicht aus dem SDK belegbar. Vor Produktivsetzung mit einem Testkonto klären und Ergebnisse hier nachtragen.

**Zugang und Token**

1. Wie ein API-Token in der FLOWFACT-Oberfläche erzeugt wird (Menüpfad, Berechtigung). Das SDK zeigt nur die Endpunkte aus 3.4, nicht die UI.
2. Ob der Header `x-ff-api-token` extern für alle benötigten Services akzeptiert wird (Entity, Schema, Multimedia, Portal-Management, Search) oder nur für eine Teilmenge.
3. Ob zusätzlich `x-ff-company-id` erforderlich ist, wenn ein API-Benutzer nur einer Company angehört.
4. Laufzeit und Widerruf des API-Tokens; das SDK kennt nur `active`, `created`, `lastLogin`. Quelle: src/service/UserService/UserService.Types.d.ts:78-85
5. Was ein "platformToken" bei `GET /public/adminUser/authenticate` ist und ob dieser Weg für Dritte offen steht. Quelle: src/service/AdminTokenService/PublicAdminUserController.js:12-24
6. Rate-Limits (Statuscode 429 ist im SDK vorgesehen, Grenzwerte nicht). Quelle: src/http/statusCodes.js:13, :25

**Entitäten**

7. Antwortkörper von `POST /schemas/{schemaId}`: vollständige Entität oder nur die ID (Typ und Kommentar widersprechen sich). Quelle: src/service/EntityService/EntityService.d.ts:20-25
8. Konkrete Estate-Schemanamen des Kontos (Gruppe `estates`, Beispiel `house_purchase`) und deren Pflichtfelder.
9. Ob `identifier` frei beschreibbar ist oder automatisch vergeben wird (Präfix-Logik).
10. Ob `POST /schemas/{schemaId}` `x-ff-version: 2` zwingend braucht und ob `PATCH` ohne Version (v1) korrekt arbeitet.
11. Exakte Fehlerkörper (Validierungsfehler) des Entity-Service.
12. Verhalten bei unbekannten Feldnamen im Payload (Ablehnung oder stilles Ignorieren).

**Suche**

13. Vollständige JSON-Form eines Flowdsl-Dokuments (Schlüssel der obersten Ebene).
14. Verfügbare Operatoren neben `LIKE`, insbesondere ein Exakt-Vergleich.
15. Ob die Suche über die Gruppe `estates` alle Estate-Schemata abdeckt und ob Papierkorb-Einträge ausgeschlossen sind.

**Bilder und Dokumente**

16. HTTP-Methode und Header für den Upload an `presignedUrl` (S3).
17. Ob der Presigned-URL-Pfad den konkreten Schemanamen erwartet oder wie im SDK `house_purchase`.
18. Album- und Kategorienamen des Estate-Schemas (z. B. für Bilder, Grundrisse, Dokumente).
19. Wie das Hauptbild bestimmt wird (Sortierung 0 oder eigenes Kennzeichen).
20. Größen- und Formatgrenzen für Bilder und PDF.

**Portale**

21. Ob `POST /publish` für den API-Benutzer zulässig ist oder Nutzerrechte (ACP) fehlen (`portalsWithoutAccessRights` in der Bulk-Antwort deutet auf Rechteprüfung). Quelle: src/service/PortalManagementService/PortalManagementService.Types.d.ts:28
22. Ob die Antwort synchron (Body) oder asynchron (leer, Kafka) erfolgt und wie der Endzustand dann zuverlässig abgefragt wird.
23. Bedeutung und Einheit von `publishBlockedUntil`, `lastUpdate`, `onlineSince` (vermutlich Unix-Millisekunden, nicht belegt).
24. Ob OpenImmo-FTP-Portale über `/publish` synchron übertragen oder zeitgesteuert exportieren.
25. Ob Portalzugangsdaten (IS24 OAuth, FTP) ausschließlich in der Oberfläche eingerichtet werden müssen.

**Phasen**

26. Ob `objectPhase` direkt per PATCH gesetzt werden darf oder nur über den Phasen-Service.

**Sonstiges**

27. Inhalt der nicht mitgelieferten `Changelog.md` (Deprecations, Portalumstellungen).
28. Verfügbarkeit einer Staging-Umgebung (`api.staging.cloudios.flowfact-prod.cloud`) für Kunden.

---

## 10. Empfohlene Reihenfolge für den Connector-Smoke-Test

Jeder Schritt bestätigt eine Annahme. Bricht ein Schritt ab, sind die folgenden nicht sinnvoll. Basis-URL Produktion: `https://api.production.cloudios.flowfact-prod.cloud`. Header bei allen Aufrufen: `x-ff-api-token`, `Accept-Language: de`, bei Bedarf `x-ff-company-id`. Quelle: Abschnitt 2 und 3.

| Nr. | Aufruf | Beweist | Erwartung | Quelle |
|---|---|---|---|---|
| 1 | `GET /user-service/users/currentUser` mit Header `x-ff-version: 2` | Token gültig, Benutzer und `companyId` bekannt | 200, `User` mit `companyId`, `type = API` | src/service/UserService/UsersV2Controller.js:9, :33; src/service/UserService/UserService.Types.d.ts:46-75 |
| 2 | `GET /company-service/company/{companyId}` | Company-Zuordnung stimmt | 200, `companyName` passt | src/service/CompanyService/CompanyController.js:47 |
| 3 | `GET /schema-service/v2/schemas?group=estates` | Schema-Service erreichbar, konkrete Estate-Schemata bekannt | 200, `entries[]` mit `name` | src/service/SchemaServiceV2/SchemaServiceV2.js:28-43 |
| 4 | `GET /schema-service/v2/schemas/{estateSchema}?extensions=all` | Feldliste und Pflichtangaben für den Mapper | 200, `properties` | src/service/SchemaServiceV2/SchemaServiceV2.js:109-111 |
| 5 | `POST /search-service/schemas/estates?page=1&size=1&withCount=true` mit Flowdsl auf `identifier` | Suche funktioniert, Dublettenprüfung möglich | 200, `totalCount` | src/service/SearchService/SearchService.js:128-133 |
| 6 | `POST /entity-service/schemas/{estateSchema}` mit Minimal-Payload (Abschnitt 4.3), Header `x-ff-version: 2` | Anlage funktioniert, ID-Rückgabe verstanden | 2xx, Antwort mit `id` | src/service/EntityService/EntityService.js:73-75 |
| 7 | `GET /entity-service/schemas/{estateSchema}/entities/{id}` | Lesen der angelegten Entität | 200, Werte in `values[]` | src/service/EntityService/EntityService.js:340 |
| 8 | `PATCH /entity-service/schemas/{estateSchema}/entities/{id}` mit `{ "headline": { "values": ["Test geändert"] } }` | Aktualisierung funktioniert | 200 | src/service/EntityService/EntityService.js:267 |
| 9 | `GET /multimedia-service/albums/schemas/{estateSchema}` | Albumnamen und Kategorien bekannt | 200, `Album[]` | src/service/MultimediaService/AlbumsController.js:19 |
| 10 | `GET /multimedia-service/items/schemas/{estateSchema}/entities/{id}/presigned-url?contentType=image/jpeg&fileName=test.jpg&fileSize=<n>` | Upload-Weg offen | 200, `presignedUrl`, `itemLink` | src/service/MultimediaService/ItemsController.js:132-138 |
| 11 | Upload an `presignedUrl`, dann `POST /multimedia-service/items/schemas/{estateSchema}/entities/{id}` | ein Bild hängt am Objekt | 2xx, `multimediaItem.id` | src/service/MultimediaService/ItemsController.js:156-163 |
| 12 | `GET /multimedia-service/items/entities/{id}?contentCategory=IMAGE` | Bild sichtbar | 200, ein Eintrag | src/service/MultimediaService/ItemsController.js:94-98 |
| 13 | `GET /portal-management-service/portals?ignoreInactivePortals=true` | Portale lesbar, Rechte vorhanden | 200, `Portal[]` mit `authenticated` | src/service/PortalManagementService/PortalController.js:20-24 |
| 14 | `GET /portal-management-service/estates/{id}/portals` | Publikationsstatus lesbar | 200, leeres Array für das Testobjekt | src/service/PortalManagementService/PortalEstateController.js:19 |
| 15 | **Kein** `POST /publish` im Smoke-Test | verhindert eine reale Veröffentlichung des Testobjekts | entfällt | src/service/PortalManagementService/PublishController.js:24 |
| 16 | `DELETE /multimedia-service/items/{mediaItemId}`, dann `DELETE /entity-service/schemas/{estateSchema}/entities/{id}` | Aufräumen, Löschrechte vorhanden | 2xx | src/service/MultimediaService/ItemsController.js:177; src/service/EntityService/EntityService.js:227 |
| 17 | `GET /entity-service/recovery/entities?schema={estateSchema}` | Testobjekt liegt im Papierkorb, Verständnis des Löschverhaltens | 200, Eintrag mit `entityId` | src/service/EntityService/EntityService.js:496-502 |

Empfehlung: Schritte 1 bis 5 zuerst auf einem Konto ohne Schreibrechte ausführen. Schritte 6 bis 17 nur mit einem eindeutig als Test gekennzeichneten `identifier` (z. B. Präfix `TEST-`), damit ein versehentlicher Portalexport erkennbar bleibt. Eine Veröffentlichung (`POST /publish`) erst nach Freigabe durch die Geschäftsführung und nach Klärung der Punkte 21 bis 25 in Abschnitt 9.

---

## 11. Verifikationsprotokoll

Stand: 11.09.2026. Jede Endpunktzeile, jeder Header-Name, jedes URL-Muster und jede Angabe zur Werteform der Abschnitte 1 bis 10 wurde gegen die zitierte Datei und Zeile im Tarball `@flowfact/api-services` 85.1.9 geprüft. Zusätzlich wurden alle Controller der Verzeichnisse EntityService, MultimediaService, PortalManagementService, IS24PublishService, SearchService, SchemaService, SchemaServiceV2, PropertyMarketingPhaseService, ObjectPhaseService und AdminTokenService auf Endpunkte durchsucht, die im Dokument fehlten.

**Umfang**

| Kennzahl | Wert |
|---|---|
| Geprüfte Einzelaussagen | 246 (davon 131 Endpunktzeilen, 115 Header-, URL-, Typ-, Enum- und Quellenangaben) |
| Bestätigt | 233 |
| Korrigiert | 13 |
| Widerlegt (kein Beleg im SDK) | 0 |
| Ergänzt (fehlende Endpunkte mit Quelle) | 34 Endpunkte in 9 neuen bzw. erweiterten Tabellenzeilen-Blöcken |

**Korrekturen (vorher, nachher)**

| Nr. | Stelle | Vorher | Nachher | Art |
|---|---|---|---|---|
| 1 | 1, Upstream-Repository | README.md:25 | README.md:26 | Zeilenangabe |
| 2 | 1, Changelog-Verweis | README.md:10 | README.md:8 | Zeilenangabe |
| 3 | 1, keine Tests | README.md:48-50 | README.md:49-51 | Zeilenangabe |
| 4 | 1, Query-Serialisierung | `qs` mit Standardoptionen | `qs` mit `addQueryPrefix: true`, sonst Standard | Inhalt |
| 5 | 5.1, Deprecated-Hinweis | EntityService.js:364, :442 | :365, :443 | Zeilenangabe |
| 6 | 5.2, Überschrift V1 | "v1-Methoden sind laut Code deprecated" | nur `fetchSchema`, `createSchema`, `updateSchema`, `deleteSchema`, `deleteAllSchema`, `fetchAllMembersOfGroup` tragen `@deprecated`; `loadStats`, `fetchAllSchemas` (v1), `fetchDataBySchemaId`, `fetchDataTypes` nicht | Inhalt |
| 7 | 5.3, `/transfers` | Body "mit Quell-Schema, Quell-Entität, `mediaItems`, `albumIds`" | Body `{ sourceSchemaName, sourceEntityId, selectedMultimediaItems, selectedAlbums }` | Inhalt |
| 8 | 5.4, `/portals/create/{portalType}` | PortalController.d.ts:117 | PortalController.d.ts:40 | Zeilenangabe |
| 9 | 5.4, `/publishing-history` | PortalEstateController.d.ts:69 | PortalEstateController.d.ts:50 | Zeilenangabe |
| 10 | 5.8 und 10 Schritt 1, `/users/currentUser` | kein Versionsheader genannt | Controller sendet `x-ff-version: 2` (UsersV2Controller.js:9) | Inhalt |
| 11 | 5.8, Typ `Company` | CompanyService.Types.d.ts:66-91 | :66-112 | Zeilenangabe |
| 12 | 7, Bedingungsobjekt | SearchService.js:346-351 | :351-356 | Zeilenangabe |
| 13 | 9 Punkt 6, Statuscode 429 | statusCodes.js:12 | :13, :25 | Zeilenangabe |

**Ergänzungen**

- 3.4: `GET /public/adminUser/authenticateAndReturnUsernameWithToken`
- 5.1: `POST /users/{userId}/hasaccess/{accessType}`, `GET /views/{viewId}/schemas/{schemaId}/entities/{entityId}`, `POST /views/{viewName}/entities`, zwei deprecated `stringify`-Endpunkte
- 5.2 V2: Schema duplizieren, `field-import`, `deleteAll`, `possiblevalues` (deprecated), Gruppen POST/PUT/DELETE, Extensions (GET/POST/PUT/DELETE), Extension-Assignments, Typ `Extension`
- 5.2 V1: Schema POST/PUT/DELETE (deprecated), `/datatypes/alltypes?transform=true`, Integrations-Endpunkte
- 5.4: ProjectsController (`/projects/{id}/publish`, `/unpublish`), ProjectsEstateController (`/estates/{id}/projects`), LocalHeroController (`/local-hero/slot-availability`, `/local-hero/estates/{id}`)
- 5.5: `DELETE /portal/{portalId}/estate/{estateId}` (IS24RealEstateController)
- 5.6: `POST /internal/schemas/{index}/count`
- 5.7: `GET /calculateTotalCommissionForAllPhases`, `DELETE /steps/{schemaId}/{entityId}`, `GET/POST /phaseconfigurations`, `DELETE /phaseconfigurations/{id}`

**Bewertung**

Alle Pfade, Methoden, Header-Namen, Header-Werte, Enum-Codes und Antworttypen des Dokuments sind im SDK belegt. Die Korrekturen betreffen überwiegend um wenige Zeilen verschobene Quellenangaben; inhaltlich relevant für den Connector sind Nr. 7 (Body-Schlüssel des Transfer-Endpunkts), Nr. 10 (Versionsheader für `/users/currentUser`) und Nr. 6 (Deprecation-Status der v1-Schemamethoden). Unverändert gilt: Das SDK belegt nur, was der Browser-Client aufruft. Ob der Header `x-ff-api-token` extern akzeptiert wird, welche Rechte der API-Benutzer hat und wie der Flowdsl-Body auf oberster Ebene aussieht, bleibt offen (Abschnitt 9).
