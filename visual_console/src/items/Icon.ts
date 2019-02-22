import { LinkedVisualConsoleProps, UnknownObject } from "../types";

import { linkedVCPropsDecoder } from "../lib";

import VisualConsoleItem, {
  VisualConsoleItemProps,
  itemBasePropsDecoder,
  VisualConsoleItemType
} from "../VisualConsoleItem";

export type IconProps = {
  type: VisualConsoleItemType.ICON;
  imageSrc: string; // URL?
} & VisualConsoleItemProps &
  LinkedVisualConsoleProps;

/**
 * Build a valid typed object from a raw object.
 * This will allow us to ensure the type safety.
 *
 * @param data Raw object.
 * @return An object representing the icon props.
 * @throws Will throw a TypeError if some property
 * is missing from the raw object or have an invalid type.
 */
export function iconPropsDecoder(data: UnknownObject): IconProps | never {
  if (typeof data.imageSrc !== "string" || data.imageSrc.length === 0) {
    throw new TypeError("invalid image src.");
  }

  return {
    ...itemBasePropsDecoder(data), // Object spread. It will merge the properties of the two objects.
    type: VisualConsoleItemType.ICON,
    imageSrc: data.imageSrc,
    ...linkedVCPropsDecoder(data) // Object spread. It will merge the properties of the two objects.
  };
}

export default class Icon extends VisualConsoleItem<IconProps> {
  createDomElement(): HTMLElement {
    const img: HTMLImageElement = document.createElement("img");
    img.className = "icon";
    img.src = this.props.imageSrc;

    return img;
  }
}
