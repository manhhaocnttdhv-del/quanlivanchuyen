# Shipper demo routes

`shipper-demo-routes.json` contains three static GeoJSON LineStrings for the Leaflet simulation. Coordinates use GeoJSON order: longitude, latitude. They represent public sample routes, not customer addresses or actual warehouse coordinates.

Source: OSRM public routing service, retrieved on 2026-11-20 with `overview=full&geometries=geojson`. Underlying road data is from [OpenStreetMap](https://www.openstreetmap.org/copyright), available under the Open Database License (ODbL). Keep this attribution with the sample data.

Recorded endpoints:

| Sample | Start (longitude, latitude) | End (longitude, latitude) |
| --- | --- | --- |
| HN | 105.857, 21.0241 | 105.8345, 21.0368 |
| HCM | 106.69802, 10.772556 | 106.699123, 10.77916 |
| DN | 108.2239, 16.0612 | 108.2302, 16.0721 |

The runtime reads these fixtures locally and never requests OSRM or geocoding. Leaflet loads raster tiles from OSM France/HOT with visible attribution. This is a small interactive demo, not an offline tile cache or a production routing service. Production traffic needs an appropriate tile provider and usage policy review.
